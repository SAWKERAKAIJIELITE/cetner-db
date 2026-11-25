<?php

namespace Tests\Feature\Records;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Records\Models\EmployeeDebt;
use App\Modules\Settings\Models\ListItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** D-040, D-042: each record's columns, as the workbook checks them, and the database checking them again. */
class RecordRulesTest extends TestCase
{
    public function test_each_kind_lists_its_columns_for_the_form(): void
    {
        $this->actingAsStaff($this->staffUser());

        $this->getJson(self::API.'/records/movements/fields')->assertOk()
            ->assertJsonPath('data.record_type.label', 'سجل الحركات')
            ->assertJsonCount(12, 'data.fields')
            ->assertJsonFragment(['key' => 'movement_type_id', 'label' => 'نوع الحركة', 'type' => 'list', 'list' => 'movement_type', 'required' => true, 'fixed' => false, 'max' => null]);
        $this->getJson(self::API.'/records/attendance/fields')->assertJsonFragment(['key' => 'month', 'fixed' => true]);
        $this->getJson(self::API.'/records/conduct/fields')->assertJsonCount(10, 'data.fields');
        $this->getJson(self::API.'/records/debts/fields')->assertJsonCount(9, 'data.fields');
        $this->getJson(self::API.'/records/salaries/fields')->assertNotFound();
    }

    public function test_the_values_are_checked_and_written_in_one_form(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->staffUser());

        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => ['description' => 'x']])->assertUnprocessable()
            ->assertJsonValidationErrors(['values.occurred_on', 'values.movement_type_id']);
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '10/03/2026', 'movement_type_id' => 99999, 'amount' => -5, 'days' => 'خمسة', 'salary' => 1,
        ]])->assertUnprocessable()->assertJsonPath('errors.values.0', __('records.unknown_fields', ['fields' => 'salary']));
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '10/03/2026', 'movement_type_id' => 99999, 'amount' => -5, 'days' => 'خمسة',
        ]])->assertUnprocessable()->assertJsonValidationErrors(['values.occurred_on', 'values.movement_type_id', 'values.amount', 'values.days']);

        $leave = $this->itemId('movement_type', 'إجازة داخلية');
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '2026-04-01', 'movement_type_id' => $leave, 'starts_on' => '2026-04-10', 'ends_on' => '2026-04-05',
        ]])->assertUnprocessable()->assertJsonValidationErrors(['values.ends_on' => __('records.ends_before_start')]);

        $set = $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '٢٠٢٦-٠٤-٠١', 'movement_type_id' => (string) $leave, 'starts_on' => '2026-04-05', 'ends_on' => '2026-04-09',
            'days' => '٥', 'course_mark' => '87.5', 'description' => '  إجازة   سنوية  ', 'entity' => '',
        ]])->assertCreated()->json('data.items.0.fields');

        $this->assertEquals(
            ['occurred_on' => '2026-04-01', 'movement_type_id' => $leave, 'description' => 'إجازة سنوية', 'course_mark' => '87.50', 'starts_on' => '2026-04-05', 'ends_on' => '2026-04-09', 'days' => 5],
            collect($set)->pluck('new_value', 'field')->all(),
        );

        // An archived item is no longer offered.
        ListItem::queryFor('movement_type')->whereKey($leave)->update(['archived_at' => now()]);
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => ['occurred_on' => '2026-04-01', 'movement_type_id' => $leave]])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.movement_type_id' => __('records.list_item_unavailable', ['field' => 'نوع الحركة'])]);
    }

    public function test_records_are_entered_for_approved_files_only(): void
    {
        $this->actingAsStaff($this->staffUser());
        $pending = $this->postJson(self::API.'/employees', ['values' => ['first_name' => 'ليلى', 'father_name' => 'سعيد', 'family_name' => 'الحسن']])->json('data.employee.id');

        $this->postJson(self::API.'/records/conduct', ['employee_id' => $pending, 'values' => ['occurred_on' => '2026-01-01', 'conduct_type_id' => $this->itemId('conduct_type', 'warning')]])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->postJson(self::API.'/records/conduct', ['employee_id' => '0195a000-0000-7000-8000-000000000000', 'values' => ['notes' => 'x']])
            ->assertUnprocessable()->assertJsonPath('errors.employee_id.0', __('records.employee_unknown'));
    }

    public function test_the_attendance_checks_its_days_and_computes_the_absence(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin());
        $post = fn (array $values) => $this->postJson(self::API.'/records/attendance', ['employee_id' => $employee->id, 'values' => $values]);

        $post(['year' => 2026, 'month' => 2, 'planned_days' => 20, 'attended_days' => 22])->assertUnprocessable()
            ->assertJsonValidationErrors(['values.attended_days' => __('records.attended_over_planned')]);
        $post(['year' => 2026, 'month' => 2, 'planned_days' => 29, 'attended_days' => 20])->assertUnprocessable()
            ->assertJsonValidationErrors(['values.planned_days' => __('records.planned_over_month', ['days' => 28])]);
        $post(['year' => 2026, 'month' => 13, 'planned_days' => 20, 'attended_days' => 20])->assertUnprocessable()->assertJsonValidationErrors('values.month');

        $post(['year' => 2026, 'month' => 2, 'planned_days' => 20, 'attended_days' => 15, 'exceptional_skills' => 'إسعافات أولية'])->assertCreated();
        $record = EmployeeAttendance::query()->sole();

        $this->getJson(self::API."/records/attendance/{$record->id}")
            ->assertJsonPath('data.computed', ['absent_days' => 5, 'absence_rate' => 0.25, 'high_absence' => true]);
        $this->getJson(self::API.'/records/attendance?high_absence=1')->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/records/attendance?high_absence=0')->assertJsonCount(0, 'data');

        // The month is the record's: a wrong month is removed and entered again.
        $this->patchJson(self::API."/records/attendance/{$record->id}", ['values' => ['month' => 3]])->assertUnprocessable()
            ->assertJsonValidationErrors(['values.month' => __('records.field_fixed', ['field' => 'الشهر'])]);
        $this->patchJson(self::API."/records/attendance/{$record->id}", ['values' => ['attended_days' => 19, 'month' => 2]])->assertCreated();
        $this->assertSame(1, (int) $record->refresh()->getAttribute('absent_days'));
        $this->patchJson(self::API."/records/attendance/{$record->id}", ['values' => ['planned_days' => 10]])->assertUnprocessable()
            ->assertJsonValidationErrors(['values.attended_days' => __('records.attended_over_planned')]);
        $this->patchJson(self::API."/records/attendance/{$record->id}", ['values' => ['attended_days' => 19]])->assertUnprocessable()
            ->assertJsonPath('errors.values.0', __('records.nothing_changed'));
        $this->patchJson(self::API."/records/attendance/{$record->id}", ['values' => ['planned_days' => null]])->assertUnprocessable()->assertJsonValidationErrors('values.planned_days');

        // The month is entered once.
        $post(['year' => 2026, 'month' => 2, 'planned_days' => 20, 'attended_days' => 20])->assertUnprocessable()
            ->assertJsonPath('errors.record.0', __('records.attendance_exists', ['month' => '2/2026']));

        foreach ([
            fn () => DB::table('employee_attendance')->where('id', $record->id)->update(['attended_days' => 25]),
            fn () => DB::table('employee_attendance')->where('id', $record->id)->update(['month' => 3]),
            fn () => DB::table('employee_attendance')->where('id', $record->id)->update(['absent_days' => 0]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('the database accepted it');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_debt_keeps_what_is_left_and_its_status(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin());
        $post = fn (array $values) => $this->postJson(self::API.'/records/debts', ['employee_id' => $employee->id, 'values' => $values]);

        $post(['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('values.amount');
        $post(['amount' => 1000, 'paid_amount' => 1500])->assertUnprocessable()->assertJsonValidationErrors(['values.paid_amount' => __('records.paid_over_amount')]);

        $post(['creditor' => 'الجمعية', 'debt_type_id' => $this->itemId('debt_type', 'سلفة على الراتب'), 'amount' => 500000, 'paid_amount' => 100000, 'monthly_installment' => 50000, 'due_on' => '2020-01-01'])->assertCreated();
        $post(['amount' => 200000, 'due_on' => '2100-01-01'])->assertCreated();
        $post(['amount' => 75000, 'paid_amount' => 75000])->assertCreated();

        $this->getJson(self::API.'/records/debts?debt_status=overdue')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.computed', ['remaining_amount' => '400000.00', 'status' => ['value' => 'overdue', 'label' => 'متأخر']]);
        $this->getJson(self::API.'/records/debts?debt_status=outstanding')->assertJsonCount(1, 'data')->assertJsonPath('data.0.computed.status.label', 'قائم');
        $this->getJson(self::API.'/records/debts?debt_status=paid')->assertJsonCount(1, 'data')->assertJsonPath('data.0.computed.status.label', 'مسدد');

        // Paying more than is left is refused when the payment is corrected.
        $debt = EmployeeDebt::query()->where('amount', 500000)->sole();
        $this->patchJson(self::API."/records/debts/{$debt->id}", ['values' => ['paid_amount' => 600000]])->assertUnprocessable();
        $this->patchJson(self::API."/records/debts/{$debt->id}", ['values' => ['paid_amount' => 150000]])->assertCreated();
        $this->assertSame('350000.00', $debt->refresh()->getAttribute('remaining_amount'));

        // The file's two debt columns, and the list's filter.
        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.computed.has_debts', true)
            ->assertJsonPath('data.computed.total_remaining', '550000.00');
        $other = $this->employeeFile();
        $this->getJson(self::API.'/employees?has_debts=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.debts_remaining', '550000.00');
        $this->getJson(self::API.'/employees?has_debts=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('data.0.debts_remaining', '0.00');
        $this->getJson(self::API."/employees/{$other->id}")->assertJsonPath('data.computed.has_debts', false);

        try {
            DB::transaction(fn () => DB::table('employee_debts')->where('id', $debt->id)->update(['paid_amount' => 999999]));
            $this->fail('the database accepted a payment over the debt');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_the_conduct_record_and_its_decision(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->staffUser())->postJson(self::API.'/records/conduct', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '2026-05-02', 'conduct_type_id' => $this->itemId('conduct_type', 'complaint'),
            'subject' => 'تأخر متكرر', 'reported_by' => 'رئيس الشعبة', 'decision' => 'إنذار خطي', 'decision_number' => '45/ق',
            'decision_on' => '2026-05-10', 'conduct_status_id' => $this->itemId('conduct_status', 'صدر القرار'), 'document_link' => 'https://docs.example/45',
        ]])->assertCreated()->assertJsonPath('data.status.value', 'pending');

        $set = $this->getJson(self::API.'/record-changes?record_type=conduct')->json('data.0.id');
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->getJson(self::API."/record-changes/{$set}")
            ->assertJsonFragment(['field' => 'conduct_status_id', 'new_display' => 'صدر القرار'])
            ->assertJsonFragment(['field' => 'decision_number', 'new_value' => '45/ق']);
    }
}
