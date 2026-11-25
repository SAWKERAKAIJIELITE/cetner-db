<?php

namespace Tests\Feature\Records;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Settings\Models\Holiday;
use App\Modules\Settings\Models\WorkSettings;
use Tests\TestCase;

/** D-041, D-043: a unit's month of attendance in one submission, and the month's planned days suggested. */
class AttendanceBatchTest extends TestCase
{
    private function month(string $employeeId, int $attended, int $month = 3): array
    {
        return ['action' => 'create', 'employee_id' => $employeeId, 'values' => ['year' => 2026, 'month' => $month, 'planned_days' => 20, 'attended_days' => $attended]];
    }

    public function test_a_months_attendance_is_sent_reviewed_and_approved_together(): void
    {
        [$a, $b, $c] = [$this->employeeFile(), $this->employeeFile(), $this->employeeFile()];
        $entry = $this->staffUser();

        $set = $this->actingAsStaff($entry)->postJson(self::API.'/records/attendance/batch', [
            'note' => 'دوام آذار — شعبة الميدان',
            'changes' => [$this->month($a->id, 20), $this->month($b->id, 18), $this->month($c->id, 12)],
        ])->assertCreated()->assertJsonPath('data.items_count', 3)->assertJsonPath('data.status.value', 'pending')
            ->assertJsonPath('data.items.2.position', 3)->json('data.id');

        $this->assertSame(0, EmployeeAttendance::query()->count());

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/record-changes/{$set}/approve")->assertOk();
        $this->assertSame(3, EmployeeAttendance::query()->count());
        $this->getJson(self::API.'/records/attendance?year=2026&month=3&high_absence=1')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $c->id);

        // The supervisor corrects two months together: at once.
        $records = EmployeeAttendance::query()->whereIn('employee_id', [$a->id, $b->id])->pluck('id', 'employee_id');
        $this->postJson(self::API.'/records/attendance/batch', ['changes' => [
            ['action' => 'update', 'record_id' => $records[$a->id], 'values' => ['attended_days' => 19]],
            ['action' => 'update', 'record_id' => $records[$b->id], 'values' => ['attended_days' => 17]],
        ]])->assertCreated()->assertJsonPath('data.status.value', 'approved')->assertJsonPath('data.self_approved', true);
        $this->assertSame(36, (int) EmployeeAttendance::query()->whereIn('employee_id', [$a->id, $b->id])->sum('attended_days'));
    }

    public function test_every_error_of_a_batch_is_reported_together_and_nothing_is_kept(): void
    {
        [$a, $b] = [$this->employeeFile(), $this->employeeFile()];
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/attendance', ['employee_id' => $b->id, 'values' => ['year' => 2026, 'month' => 3, 'planned_days' => 20, 'attended_days' => 20]])->assertCreated();

        $this->actingAsStaff($this->staffUser())->postJson(self::API.'/records/attendance/batch', ['changes' => [
            $this->month($a->id, 25),
            $this->month($b->id, 18),
            $this->month($a->id, 18),
            ['action' => 'create', 'employee_id' => $a->id, 'values' => ['year' => 2026, 'month' => 4]],
        ]])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'changes.0.values.attended_days' => __('records.attended_over_planned'),
                'changes.1' => __('records.attendance_exists', ['month' => '3/2026']),
                'changes.3.values.planned_days',
                'changes.3.values.attended_days',
            ]);

        $this->assertSame(1, EmployeeAttendance::query()->count());
        $this->assertDatabaseCount('record_change_sets', 1);
    }

    public function test_a_month_is_entered_once_in_a_batch_and_while_waiting(): void
    {
        $a = $this->employeeFile();
        $this->actingAsStaff($this->staffUser());

        $this->postJson(self::API.'/records/attendance/batch', ['changes' => [$this->month($a->id, 20), $this->month($a->id, 19)]])->assertUnprocessable()
            ->assertJsonValidationErrors(['changes.1' => __('records.attendance_twice_in_batch', ['month' => '3/2026'])]);

        $this->postJson(self::API.'/records/attendance/batch', ['changes' => [$this->month($a->id, 20)]])->assertCreated();
        $this->actingAsStaff($this->staffUser())->postJson(self::API.'/records/attendance/batch', ['changes' => [$this->month($a->id, 19), $this->month($a->id, 19, 4)]])->assertUnprocessable()
            ->assertJsonValidationErrors(['changes.0' => __('records.attendance_waiting', ['month' => '3/2026'])]);
    }

    public function test_one_stale_change_refuses_the_whole_batch(): void
    {
        [$a, $b] = [$this->employeeFile(), $this->employeeFile()];
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/attendance/batch', ['changes' => [$this->month($a->id, 20), $this->month($b->id, 20)]])->assertCreated();
        $records = EmployeeAttendance::query()->pluck('id', 'employee_id');

        $set = $this->actingAsStaff($this->staffUser())->postJson(self::API.'/records/attendance/batch', ['changes' => [
            ['action' => 'update', 'record_id' => $records[$a->id], 'values' => ['attended_days' => 18]],
            ['action' => 'update', 'record_id' => $records[$b->id], 'values' => ['attended_days' => 17]],
        ]])->json('data.id');

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->patchJson(self::API."/records/attendance/{$records[$b->id]}", ['values' => ['attended_days' => 16]])->assertCreated();

        $this->postJson(self::API."/record-changes/{$set}/approve")->assertUnprocessable()
            ->assertJsonValidationErrors(['change_set' => __('records.change_stale', ['positions' => '2'])]);
        $this->assertSame(20, EmployeeAttendance::query()->findOrFail($records[$a->id])->attended_days);
    }

    public function test_a_batch_has_a_limit_and_removals_go_one_by_one(): void
    {
        config(['hr.records_batch_limit' => 2]);
        $a = $this->employeeFile();
        $this->actingAsStaff($this->staffUser());

        $this->postJson(self::API.'/records/attendance/batch', ['changes' => [$this->month($a->id, 1, 1), $this->month($a->id, 1, 2), $this->month($a->id, 1, 3)]])
            ->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->postJson(self::API.'/records/attendance/batch', ['changes' => [['action' => 'delete', 'record_id' => '0195a000-0000-7000-8000-000000000000', 'values' => []]]])
            ->assertUnprocessable()->assertJsonValidationErrors('changes.0.action');
    }

    public function test_the_months_planned_days_are_suggested_from_the_days_off_and_the_holidays(): void
    {
        $this->actingAsStaff($this->staffUser());

        // March 2026: 31 days, 8 Fridays and Saturdays; Eid from Friday 20 to Wednesday 25 and an overlapping
        // holiday on the 24th and 25th take 4 working days, each once.
        Holiday::query()->forceCreate(['name' => 'عيد الفطر', 'starts_on' => '2026-03-20', 'ends_on' => '2026-03-25']);
        Holiday::query()->forceCreate(['name' => 'عطلة متداخلة', 'starts_on' => '2026-03-24', 'ends_on' => '2026-03-25']);
        Holiday::query()->forceCreate(['name' => 'خارج الشهر', 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-01']);

        $this->getJson(self::API.'/records/attendance/planned-days?year=2026&month=3')->assertOk()->assertExactJson(['data' => [
            'year' => 2026, 'month' => 3, 'days_in_month' => 31, 'weekly_days_off' => [5, 6], 'working_days' => 23,
            'holiday_dates' => ['2026-03-22', '2026-03-23', '2026-03-24', '2026-03-25'],
            'planned_days' => 19,
        ]]);

        // Friday only: Saturday the 21st becomes a working day, and a holiday.
        WorkSettings::query()->whereKey(1)->update(['work_days_per_week' => 6, 'weekly_days_off' => '[5]']);
        $this->getJson(self::API.'/records/attendance/planned-days?year=2026&month=3')->assertJsonPath('data.working_days', 27)
            ->assertJsonPath('data.planned_days', 22);
        $this->getJson(self::API.'/records/attendance/planned-days?year=2026')->assertUnprocessable();
    }
}
