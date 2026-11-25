<?php

namespace Tests\Feature\Records;

use App\Modules\Identity\Enums\StaffRole;
use Tests\TestCase;

/** The registers as the design filters and sorts them (Step 4.1), in the hiring system's list shape. */
class RecordRegisterTest extends TestCase
{
    public function test_a_register_finds_by_employee_unit_period_and_type_and_keeps_its_filters_in_the_links(): void
    {
        $this->actingAsStaff($this->admin());
        $top = $this->postJson(self::API.'/settings/org-units', ['name' => 'القيادة'])->json('data.id');
        $branch = $this->postJson(self::API.'/settings/org-units', ['name' => 'الفرع', 'parent_id' => $top])->json('data.id');
        $other = $this->postJson(self::API.'/settings/org-units', ['name' => 'مديرية أخرى'])->json('data.id');

        $a = $this->employeeFile(['first_name' => 'فاطمة', 'personal_number' => '812001', 'org_unit_id' => $branch]);
        $b = $this->employeeFile(['first_name' => 'سامي', 'personal_number' => '812002', 'org_unit_id' => $other]);
        $this->actingAsStaff($this->admin());

        $move = fn ($who, $on, $code, $amount) => $this->postJson(self::API.'/records/movements', ['employee_id' => $who->id, 'values' => [
            'occurred_on' => $on, 'movement_type_id' => $this->itemId('movement_type', $code), 'amount' => $amount,
        ]])->assertCreated();
        $move($a, '2026-01-05', 'reward', 100);
        $move($a, '2026-03-05', 'deduction', 300);
        $move($b, '2026-02-05', 'reward', 200);

        // «الرقم الذاتي» instead of the id; an unknown number is refused.
        $this->postJson(self::API.'/records/movements', ['personal_number' => '٨١٢٠٠٢', 'values' => [
            'occurred_on' => '2026-04-05', 'movement_type_id' => $this->itemId('movement_type', 'reward'), 'amount' => 50,
        ]])->assertCreated()->assertJsonPath('data.items.0.employee.id', $b->id);
        $this->postJson(self::API.'/records/movements', ['personal_number' => '999999', 'values' => ['occurred_on' => '2026-04-05', 'movement_type_id' => $this->itemId('movement_type', 'reward')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['personal_number' => __('records.employee_unknown')]);

        $list = fn (string $query) => $this->getJson(self::API.'/records/movements?'.$query)->assertOk();

        $list('q=فاطمه')->assertJsonCount(2, 'data');
        $list('q=812002')->assertJsonCount(2, 'data');
        $list("org_unit_id={$top}")->assertJsonCount(2, 'data')->assertJsonPath('data.0.employee.org_unit', 'القيادة / الفرع');
        $list("org_unit_id={$top}&include_sub_units=0")->assertJsonCount(0, 'data');
        $list('from=2026-02-01&to=2026-03-31')->assertJsonCount(2, 'data');
        $list('type_id='.$this->itemId('movement_type', 'reward'))->assertJsonCount(3, 'data');
        $list('sort=amount&direction=asc')->assertJsonPath('data.0.fields.amount.value', '50.00')->assertJsonPath('data.3.fields.amount.value', '300.00');
        $list('sort=date&direction=asc')->assertJsonPath('data.0.fields.occurred_on.value', '2026-01-05');
        $list('per_page=5&type_id='.$this->itemId('movement_type', 'reward'))->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('links.first', fn ($url) => str_contains($url, 'type_id=') && str_contains($url, 'per_page=5'));
        $this->getJson(self::API.'/records/movements?per_page=4')->assertUnprocessable();
        $this->getJson(self::API.'/records/movements?sort=remaining_amount')->assertUnprocessable();
    }

    public function test_the_attendance_register_by_months_and_the_branchs_absence_threshold(): void
    {
        $a = $this->employeeFile();
        $this->actingAsStaff($this->admin());

        foreach ([[1, 20], [2, 18], [3, 16]] as [$month, $attended]) {
            $this->postJson(self::API.'/records/attendance', ['employee_id' => $a->id, 'values' => ['year' => 2026, 'month' => $month, 'planned_days' => 20, 'attended_days' => $attended]])->assertCreated();
        }

        // A half day.
        $this->postJson(self::API.'/records/attendance', ['employee_id' => $a->id, 'values' => ['year' => 2026, 'month' => 4, 'planned_days' => 20, 'attended_days' => 19.5]])->assertCreated();
        $this->postJson(self::API.'/records/attendance', ['employee_id' => $a->id, 'values' => ['year' => 2026, 'month' => 5, 'planned_days' => 20, 'attended_days' => 19.25]])
            ->assertUnprocessable()->assertJsonValidationErrors('values.attended_days');

        $this->getJson(self::API.'/records/attendance?from=2026-02-15&to=2026-03-02')->assertJsonCount(2, 'data');
        $this->getJson(self::API.'/records/attendance?no_absence=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.fields.month.value', 1);
        $this->getJson(self::API.'/records/attendance?sort=absence_rate&direction=desc')->assertJsonPath('data.0.fields.month.value', 3)
            ->assertJsonPath('data.0.computed.absent_days', 4)->assertJsonPath('data.0.computed.high_absence', true);
        $this->getJson(self::API.'/records/attendance?high_absence=1')->assertJsonCount(1, 'data');

        // The threshold is the branch's: at 10%, February (10%) is flagged too.
        $this->putJson(self::API.'/settings/work', ['high_absence_rate' => 10])->assertOk();
        $this->getJson(self::API.'/records/attendance?high_absence=1')->assertJsonCount(2, 'data');
        $this->getJson(self::API.'/records/attendance?month=4')->assertJsonPath('data.0.computed.absent_days', 0.5)
            ->assertJsonPath('data.0.fields.attended_days.value', 19.5);
    }

    public function test_the_conduct_and_debts_registers_filters(): void
    {
        $a = $this->employeeFile();
        $this->actingAsStaff($this->admin());
        $this->postJson(self::API.'/records/conduct', ['employee_id' => $a->id, 'values' => [
            'occurred_on' => '2026-05-01', 'conduct_type_id' => $this->itemId('conduct_type', 'complaint'), 'conduct_status_id' => $this->itemId('conduct_status', 'under_investigation'),
        ]])->assertCreated();
        $this->postJson(self::API.'/records/conduct', ['employee_id' => $a->id, 'values' => ['occurred_on' => '2026-05-02', 'conduct_type_id' => $this->itemId('conduct_type', 'thanks')]])->assertCreated();
        $this->getJson(self::API.'/records/conduct?conduct_status_id='.$this->itemId('conduct_status', 'under_investigation'))->assertJsonCount(1, 'data');

        $this->postJson(self::API.'/records/debts', ['employee_id' => $a->id, 'values' => ['creditor' => 'الجمعية التعاونية', 'amount' => 100]])->assertCreated();
        $this->postJson(self::API.'/records/debts', ['employee_id' => $a->id, 'values' => ['creditor' => 'المصرف', 'amount' => 900, 'due_on' => '2020-01-01']])->assertCreated();
        $this->getJson(self::API.'/records/debts?creditor=التعاونية')->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/records/debts?sort=remaining_amount&direction=desc')->assertJsonPath('data.0.fields.creditor.value', 'المصرف');
    }

    public function test_a_waiting_addition_is_in_the_register_but_in_no_total(): void
    {
        $a = $this->employeeFile();
        $this->actingAsStaff($this->staffUser())->postJson(self::API.'/records/debts', ['employee_id' => $a->id, 'values' => ['amount' => 5000]])->assertCreated();

        $this->getJson(self::API.'/records/debts')->assertJsonCount(1, 'data')->assertJsonPath('data.0.review_status', 'pending')
            ->assertJsonPath('data.0.computed.remaining_amount', '5000.00');
        $this->getJson(self::API."/employees/{$a->id}")->assertJsonPath('data.computed.has_debts', false);
        $this->getJson(self::API."/employees/{$a->id}/records/summary")->assertJsonPath('data.debts.open', 0);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API.'/record-changes/'.$this->getJson(self::API.'/record-changes')->json('data.0.id').'/approve')->assertOk();
        $this->getJson(self::API.'/records/debts')->assertJsonPath('data.0.review_status', 'approved')->assertJsonPath('data.0.review_status_label', 'معتمد');
        $this->getJson(self::API."/employees/{$a->id}")->assertJsonPath('data.computed.has_debts', true);
    }
}
