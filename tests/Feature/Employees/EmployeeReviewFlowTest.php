<?php

namespace Tests\Feature\Employees;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeSet;
use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** D-023, D-033: entries wait for review; reviewers apply at once; the file shows the approved value with the pending one. */
class EmployeeReviewFlowTest extends TestCase
{
    private function newEmployee(array $extra = []): array
    {
        return ['values' => array_merge([
            'personal_number' => '٧٧١٢٠',
            'first_name' => 'سامر',
            'father_name' => 'خالد',
            'family_name' => 'العلي',
            'national_number' => '٠٥٥٥١٢٣٤٥٦٧',
            'gender_id' => $this->itemId('gender', 'male'),
            'birth_date' => '1992-03-15',
        ], $extra), 'note' => 'من استمارة التثبيت'];
    }

    public function test_data_entry_adds_an_employee_who_waits_for_the_supervisor(): void
    {
        $entry = $this->staffUser();
        $response = $this->actingAsStaff($entry)->postJson(self::API.'/employees', $this->newEmployee())->assertCreated()
            ->assertJsonPath('data.kind', 'create')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.employee.full_name', 'سامر خالد العلي')
            ->assertJsonPath('data.can_decide', false);

        $employee = Employee::query()->findOrFail($response->json('data.employee.id'));
        $this->assertSame('pending', $employee->file_status->value);
        $this->assertNull($employee->first_name, 'nothing is in the file before approval');

        // The file shows nothing approved, the proposed values beside each field.
        $this->getJson(self::API."/employees/{$employee->id}")->assertOk()
            ->assertJsonPath('data.file_status', 'pending')
            ->assertJsonPath('data.fields.national_number.value', null)
            ->assertJsonPath('data.fields.national_number.pending.0.value', '05551234567')
            ->assertJsonPath('data.fields.gender_id.pending.0.display', 'ذكر');

        $this->getJson(self::API.'/employees')->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/employees?file_status=pending')->assertJsonCount(1, 'data');

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->getJson(self::API.'/employee-changes')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_decide', true);

        $this->postJson(self::API.'/employee-changes/'.$response->json('data.id').'/approve')->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.self_approved', false)
            ->assertJsonPath('data.reviewed_by.id', $supervisor->id);

        $employee->refresh();
        $this->assertSame('active', $employee->file_status->value);
        $this->assertSame('05551234567', $employee->national_number);
        $this->assertGreaterThan(0, (float) $employee->completeness);
        $this->assertSame('سامر خالد العلي', $employee->fullName());
        $this->assertDatabaseHas('audit_events', ['event' => 'employees.change_approved', 'actor_id' => $supervisor->id]);
    }

    public function test_the_administrator_adds_at_once_and_the_supervisor_cannot_add(): void
    {
        $this->actingAsStaff($this->admin())->postJson(self::API.'/employees', $this->newEmployee())->assertCreated()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.self_approved', true);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API.'/employees', $this->newEmployee(['national_number' => '1234567']))->assertForbidden();
        $this->actingAsStaff($this->staffUser(StaffRole::GeneralManager))->postJson(self::API.'/employees', $this->newEmployee(['national_number' => '7654321']))->assertForbidden();
    }

    public function test_a_change_shows_beside_the_approved_value_until_approved_and_then_is_history(): void
    {
        $employee = $this->employeeFile(['height_cm' => 175, 'residence_city' => 'حمص']);
        $entry = $this->staffUser();

        $set = $this->actingAsStaff($entry)->patchJson(self::API."/employees/{$employee->id}", ['values' => [
            'height_cm' => '176',
            'residence_city' => 'حمص',          // unchanged: not kept
            'phone' => '٠٩٩١ ٢٣٤ ٥٦٧',
        ]])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.old_display', '175.0')
            ->assertJsonPath('data.items.0.new_display', '176.0')
            ->json('data.id');

        $this->getJson(self::API."/employees/{$employee->id}")->assertOk()
            ->assertJsonPath('data.fields.height_cm.value', '175.0')
            ->assertJsonPath('data.fields.height_cm.pending.0.value', '176.0')
            ->assertJsonPath('data.fields.phone.pending.0.value', '0991234567')
            ->assertJsonPath('data.pending_change_sets.0.fields_count', 2);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/employee-changes/{$set}/approve", ['note' => 'مطابق'])->assertOk();

        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.fields.height_cm.value', '176.0')
            ->assertJsonPath('data.fields.height_cm.pending', []);

        $this->getJson(self::API."/employees/{$employee->id}/history?field=height_cm")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.old_value', '175.0')->assertJsonPath('data.0.new_value', '176.0')
            ->assertJsonPath('data.0.submitted_by.id', $entry->id);
    }

    public function test_the_supervisors_own_update_takes_effect_at_once_and_is_logged(): void
    {
        $employee = $this->employeeFile();
        $supervisor = $this->staffUser(StaffRole::Supervisor);

        $this->actingAsStaff($supervisor)->patchJson(self::API."/employees/{$employee->id}", ['values' => ['notes' => 'تصحيح من المشرف']])
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.self_approved', true);

        $this->assertSame('تصحيح من المشرف', $employee->refresh()->notes);
        $this->assertDatabaseHas('audit_events', ['event' => 'employees.change_submitted', 'actor_id' => $supervisor->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'employees.change_approved', 'actor_id' => $supervisor->id]);
    }

    public function test_a_refusal_needs_a_reason_and_withdrawal_is_the_authors(): void
    {
        $entry = $this->staffUser();
        $created = $this->actingAsStaff($entry)->postJson(self::API.'/employees', $this->newEmployee())->json('data');
        $supervisor = $this->staffUser(StaffRole::Supervisor);

        $this->actingAsStaff($supervisor)->postJson(self::API."/employee-changes/{$created['id']}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(self::API."/employee-changes/{$created['id']}/reject", ['reason' => 'الرقم الوطني غير مقروء'])->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertSame('rejected', Employee::query()->find($created['employee']['id'])->file_status->value);

        $second = $this->actingAsStaff($entry)->postJson(self::API.'/employees', $this->newEmployee())->json('data');
        $this->actingAsStaff($this->staffUser())->postJson(self::API."/employee-changes/{$second['id']}/withdraw")->assertForbidden();
        $this->actingAsStaff($entry)->postJson(self::API."/employee-changes/{$second['id']}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertSame('withdrawn', Employee::query()->find($second['employee']['id'])->file_status->value);

        $this->actingAsStaff($supervisor)->postJson(self::API."/employee-changes/{$second['id']}/approve")->assertUnprocessable();
    }

    public function test_data_entry_does_not_approve_and_nobody_approves_their_own(): void
    {
        $employee = $this->employeeFile();
        $entry = $this->staffUser();
        $set = $this->actingAsStaff($entry)->patchJson(self::API."/employees/{$employee->id}", ['values' => ['notes' => 'x']])->json('data.id');

        $this->postJson(self::API."/employee-changes/{$set}/approve")->assertForbidden();
        $this->actingAsStaff($this->staffUser())->postJson(self::API."/employee-changes/{$set}/approve")->assertForbidden();

        // A supervisor's identity change waits; that supervisor cannot approve it.
        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $own = $this->actingAsStaff($supervisor)->patchJson(self::API."/employees/{$employee->id}", ['values' => ['mother_name' => 'فاطمة']])
            ->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson(self::API."/employee-changes/{$own}/approve")->assertForbidden()->assertExactJson(['message' => __('employees.own_change')]);
    }

    public function test_a_change_made_stale_by_another_is_refused_not_merged(): void
    {
        $employee = $this->employeeFile(['residence_city' => 'حمص']);
        $this->actingAsStaff($this->staffUser());
        $first = $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['residence_city' => 'حماة']])->json('data.id');
        $this->actingAsStaff($this->staffUser());
        $second = $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['residence_city' => 'حلب']])->json('data.id');

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));
        $this->postJson(self::API."/employee-changes/{$first}/approve")->assertOk();
        $this->postJson(self::API."/employee-changes/{$second}/approve")->assertUnprocessable()->assertJsonValidationErrors('change_set');
        $this->assertSame('حماة', $employee->refresh()->residence_city);
    }

    public function test_values_are_checked_with_the_fields_arabic_names(): void
    {
        $employee = $this->employeeFile();
        $archived = $this->itemId('blood_type', 'AB-');
        DB::table('blood_types')->where('id', $archived)->update(['archived_at' => now()]);

        $response = $this->actingAsStaff($this->staffUser())->patchJson(self::API."/employees/{$employee->id}", ['values' => [
            'birth_date' => '15/03/1990',
            'quran_parts' => 31,
            'phone' => 'abc',
            'blood_type_id' => $archived,
            'marital_status_id' => 999999,
            'contract_starts_on' => '2026-01-01',
            'contract_ends_on' => '2025-01-01',
        ]])->assertUnprocessable();

        $this->assertStringContainsString('تاريخ الميلاد', $response->json('errors')['values.birth_date'][0]);
        $this->assertStringContainsString('عدد الأجزاء المحفوظة', $response->json('errors')['values.quran_parts'][0]);
        $response->assertJsonValidationErrors(['values.phone', 'values.blood_type_id', 'values.marital_status_id']);

        $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['no_such_field' => 1]])->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['contract_starts_on' => '2026-01-01', 'contract_ends_on' => '2025-01-01']])
            ->assertUnprocessable()->assertJsonFragment(['values.contract_ends_on' => [__('employees.contract_ends_before_start')]]);
        $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['first_name' => 'أحمد']])->assertUnprocessable()
            ->assertJsonPath('errors.values.0', __('employees.nothing_changed'));
    }

    public function test_a_national_number_is_one_persons_in_the_file_and_in_the_queue(): void
    {
        $this->employeeFile(['national_number' => '11111111111']);
        $entry = $this->staffUser();

        $this->actingAsStaff($entry)->postJson(self::API.'/employees', $this->newEmployee(['national_number' => '11111111111']))->assertUnprocessable()
            ->assertJsonFragment(['values.national_number' => [__('employees.unique_taken', ['field' => 'الرقم الوطني'])]]);

        $this->postJson(self::API.'/employees', $this->newEmployee(['national_number' => '22222222222']))->assertCreated();
        $this->postJson(self::API.'/employees', $this->newEmployee(['national_number' => '22222222222']))->assertUnprocessable()
            ->assertJsonFragment(['values.national_number' => [__('employees.unique_waiting', ['field' => 'الرقم الوطني'])]]);
    }

    public function test_a_file_needs_a_name_and_the_history_cannot_be_rewritten(): void
    {
        $this->actingAsStaff($this->staffUser())->postJson(self::API.'/employees', ['values' => ['national_number' => '3333333']])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.first_name', 'values.father_name', 'values.family_name']);

        $employee = $this->employeeFile();
        $set = EmployeeChangeSet::query()->where('employee_id', $employee->id)->sole();

        foreach ([
            fn () => DB::table('employee_change_items')->where('change_set_id', $set->id)->update(['new_value' => json_encode('x')]),
            fn () => DB::table('employee_change_items')->where('change_set_id', $set->id)->delete(),
            fn () => DB::table('employee_change_sets')->where('id', $set->id)->update(['review_note' => 'تعديل لاحق']),
            fn () => DB::table('employee_change_sets')->where('id', $set->id)->delete(),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('the history was rewritten');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_general_manager_reads_and_changes_nothing(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->staffUser(StaffRole::GeneralManager));

        $this->getJson(self::API."/employees/{$employee->id}")->assertOk();
        $this->getJson(self::API.'/employees')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['notes' => 'x']])->assertForbidden();
    }
}
