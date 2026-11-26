<?php

namespace Tests\Feature\Records;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Records\Models\EmployeeMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** D-048 (owner, 25 Nov): an approved movement updates the employee's file, once its day has come. */
class MovementToFileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 09:00:00');
    }

    private function move(Employee $employee, string $code, string $on, array $extra = []): TestResponse
    {
        return $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => $on, 'movement_type_id' => $this->itemId('movement_type', $code),
        ] + $extra]);
    }

    private function unit(string $name): int
    {
        return $this->actingAsStaff($this->admin())->postJson(self::API.'/settings/org-units', ['name_ar' => $name])->json('data.id');
    }

    public function test_an_approved_discharge_updates_the_file_and_its_history(): void
    {
        $employee = $this->employeeFile(['employee_status_id' => $this->itemId('employee_status', 'active')]);
        $set = $this->actingAsStaff($this->staffUser())->move($employee, 'discharge', '2026-06-10')->assertCreated()->json('data.id');

        // Waiting, it changes nothing.
        $this->assertNull($employee->refresh()->discharge_date);
        $this->getJson(self::API.'/records/movements')->assertJsonPath('data.0.computed.file_fields', ['job_status_id', 'employee_status_id', 'discharge_date']);

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->postJson(self::API."/record-changes/{$set}/approve")->assertOk();

        $employee->refresh();
        $this->assertSame('2026-06-10', $employee->discharge_date->format('Y-m-d'));
        $this->assertSame($this->itemId('job_status', 'discharged'), $employee->job_status_id);
        $this->assertSame($this->itemId('employee_status', 'inactive'), $employee->employee_status_id);

        $movement = EmployeeMovement::query()->sole();
        $this->assertNotNull($movement->applied_to_file_at);
        $this->getJson(self::API."/records/movements/{$movement->id}")->assertJsonPath('data.computed.file_change_set_id', $movement->file_change_set_id);

        // In the file's history, by the movement's reviewer, as «تطبيق حركة».
        $history = $this->getJson(self::API."/employees/{$employee->id}/history?field=discharge_date")->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('movement', $history['kind']);
        $this->assertSame('تطبيق حركة', $history['kind_label']);
        $this->assertSame($supervisor->id, $history['approved_by']['id']);
        $this->assertSame($movement->file_change_set_id, $history['change_set_id']);
    }

    public function test_each_type_takes_its_own_targets(): void
    {
        $employee = $this->employeeFile();
        $unit = $this->unit('مديرية الإمداد');
        $this->actingAsStaff($this->admin());

        $this->move($employee, 'transfer', '2026-06-01')->assertUnprocessable()
            ->assertJsonValidationErrors(['values.new_org_unit_id' => __('records.movement_target_required', ['field' => 'الجهة الجديدة'])]);
        $this->move($employee, 'reward', '2026-06-01', ['new_org_unit_id' => $unit])->assertUnprocessable()
            ->assertJsonValidationErrors(['values.new_org_unit_id' => __('records.movement_target_unused', ['field' => 'الجهة الجديدة'])]);
        $this->move($employee, 'promotion', '2026-06-01')->assertUnprocessable()
            ->assertJsonValidationErrors(['values.new_position_level_id' => __('records.movement_target_one_of')]);
        $this->move($employee, 'status_change', '2026-06-01')->assertUnprocessable()->assertJsonValidationErrors('values.new_job_status_id');

        // The administrator's own movements apply at once.
        $this->move($employee, 'transfer', '2026-06-01', ['new_org_unit_id' => $unit])->assertCreated()
            ->assertJsonFragment(['field' => 'new_org_unit_id', 'new_display' => 'مديرية الإمداد']);
        $accountant = $this->postJson(self::API.'/settings/lists/job_title/items', ['name_ar' => 'محاسب'])->json('data.id');
        $this->move($employee, 'promotion', '2026-06-02', ['new_job_title_id' => $accountant])->assertCreated();
        $this->move($employee, 'appointment', '2026-06-03')->assertCreated();

        $employee->refresh();
        $this->assertSame($unit, $employee->org_unit_id);
        $this->assertSame($accountant, $employee->job_title_id);
        $this->assertSame('2026-06-03', $employee->appointment_date->format('Y-m-d'));

        // A reward changes nothing.
        $this->move($employee, 'reward', '2026-06-04', ['amount' => 100])->assertCreated();
        $this->assertNull(EmployeeMovement::query()->where('occurred_on', '2026-06-04')->value('applied_to_file_at'));
    }

    public function test_a_movement_for_a_later_day_waits_for_it(): void
    {
        $employee = $this->employeeFile();
        $unit = $this->unit('الفرع الشمالي');
        $this->actingAsStaff($this->admin())->move($employee, 'transfer', '2026-07-01', ['new_org_unit_id' => $unit])->assertCreated();
        $this->assertNull($employee->refresh()->org_unit_id);

        $this->artisan('records:apply-due-movements')->assertSuccessful();
        $this->assertNull($employee->refresh()->org_unit_id, 'not before its day');

        Carbon::setTestNow('2026-07-01 00:10:00');
        $this->artisan('records:apply-due-movements')->expectsOutputToContain('1 movement(s)')->assertSuccessful();
        $this->assertSame($unit, $employee->refresh()->org_unit_id);
        $this->artisan('records:apply-due-movements')->expectsOutputToContain('0 movement(s)')->assertSuccessful();
    }

    public function test_an_old_movement_entered_late_does_not_undo_a_later_one(): void
    {
        $employee = $this->employeeFile();
        [$a, $b] = [$this->unit('الوحدة أ'), $this->unit('الوحدة ب')];
        $this->actingAsStaff($this->admin());

        $this->move($employee, 'transfer', '2026-05-01', ['new_org_unit_id' => $b])->assertCreated();
        $this->move($employee, 'transfer', '2026-03-01', ['new_org_unit_id' => $a])->assertCreated();

        $this->assertSame($b, $employee->refresh()->org_unit_id);
        $old = EmployeeMovement::query()->where('occurred_on', '2026-03-01')->sole();
        $this->assertNotNull($old->applied_to_file_at);
        $this->assertNull($old->file_change_set_id, 'nothing taken from it');
    }

    public function test_a_waiting_change_of_the_same_field_becomes_stale_and_what_was_taken_stays(): void
    {
        $employee = $this->employeeFile();
        $unit = $this->unit('مديرية الإمداد');
        $other = $this->unit('مديرية أخرى');

        $change = $this->actingAsStaff($this->staffUser())->patchJson(self::API."/employees/{$employee->id}", ['values' => ['org_unit_id' => $other]])->json('data.id');
        $this->actingAsStaff($this->admin())->move($employee, 'transfer', '2026-06-01', ['new_org_unit_id' => $unit])->assertCreated();

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/employee-changes/{$change}/approve")->assertUnprocessable();
        $this->assertSame($unit, $employee->refresh()->org_unit_id);

        $movement = EmployeeMovement::query()->sole();

        try {
            DB::transaction(fn () => DB::table('employee_movements')->where('id', $movement->id)->update(['applied_to_file_at' => null, 'file_change_set_id' => null]));
            $this->fail('a taken movement was untaken');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
