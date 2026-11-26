<?php

namespace Tests\Feature\Records;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Settings\Models\ListItem;
use Tests\TestCase;

/** D-044: «مؤشرات السنة المختارة» and the report's latest records, with the workbook's rules. */
class RecordSummaryTest extends TestCase
{
    public function test_the_years_indicators_follow_the_workbook(): void
    {
        $employee = $this->employeeFile();
        $other = $this->employeeFile();
        $this->actingAsStaff($this->admin());

        $move = fn (string $on, string $type, array $extra = [], ?string $who = null) => $this->postJson(self::API.'/records/movements', [
            'employee_id' => $who ?? $employee->id,
            'values' => ['occurred_on' => $on, 'movement_type_id' => $this->itemId('movement_type', $type)] + $extra,
        ])->assertCreated();

        // A leave's days are its calendar days, both ends included (owner, 25 Nov).
        $move('2026-01-10', 'leave_internal', ['starts_on' => '2026-01-10', 'ends_on' => '2026-01-12']);
        $move('2026-02-10', 'leave_sick', ['starts_on' => '2026-02-10', 'ends_on' => '2026-02-13']);
        $move('2025-12-20', 'leave_external', ['starts_on' => '2025-12-20', 'ends_on' => '2025-12-29']);
        $move('2026-03-01', 'course', ['course_mark' => 91]);
        $move('2026-03-05', 'reward', ['amount' => 15000]);
        $move('2026-04-05', 'reward', ['amount' => 5000]);
        $move('2026-04-06', 'deduction', ['amount' => 2500]);
        $move('2026-04-06', 'reward', ['amount' => 999999], $other->id);

        // A leave type the settings managers add counts as a leave, as in the workbook (its name begins with «إجازة»),
        // spelled without the hamza too.
        $this->postJson(self::API.'/settings/lists/movement_type/items', ['name_ar' => 'اجازة أمومة'])->assertCreated();
        $move('2026-05-01', 'اجازة أمومة', ['starts_on' => '2026-05-01', 'ends_on' => '2026-05-05']);

        // A leave without its period is refused.
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '2026-06-01', 'movement_type_id' => $this->itemId('movement_type', 'leave_emergency'),
        ]])->assertUnprocessable()->assertJsonValidationErrors(['values.starts_on' => __('records.leave_needs_period')]);

        // Renaming a leave type keeps it a leave (its code).
        ListItem::queryFor('movement_type')->where('code', 'leave_internal')->update(['label' => 'عطلة داخلية']);

        // Renaming a coded item changes nothing.
        ListItem::queryFor('movement_type')->where('code', 'reward')->update(['label' => 'مكافأة مالية']);

        foreach ([[1, 20, 18], [2, 20, 20], [3, 22, 11]] as [$month, $planned, $attended]) {
            $this->postJson(self::API.'/records/attendance', ['employee_id' => $employee->id, 'values' => ['year' => 2026, 'month' => $month, 'planned_days' => $planned, 'attended_days' => $attended]])->assertCreated();
        }

        $this->postJson(self::API.'/records/attendance', ['employee_id' => $employee->id, 'values' => ['year' => 2025, 'month' => 12, 'planned_days' => 20, 'attended_days' => 0]])->assertCreated();

        foreach ([['complaint', '2026-01-02'], ['complaint', '2026-06-02'], ['warning', '2026-06-03'], ['thanks', '2026-07-01'], ['punishment', '2025-07-01']] as [$type, $on]) {
            $this->postJson(self::API.'/records/conduct', ['employee_id' => $employee->id, 'values' => ['occurred_on' => $on, 'conduct_type_id' => $this->itemId('conduct_type', $type)]])->assertCreated();
        }

        foreach ([['amount' => 300000, 'paid_amount' => 100000, 'due_on' => '2020-01-01'], ['amount' => 50000], ['amount' => 1000, 'paid_amount' => 1000]] as $debt) {
            $this->postJson(self::API.'/records/debts', ['employee_id' => $employee->id, 'values' => $debt])->assertCreated();
        }

        $summary = $this->actingAsStaff($this->staffUser(StaffRole::GeneralManager))
            ->getJson(self::API."/employees/{$employee->id}/records/summary?year=2026")->assertOk()->json('data');

        $this->assertSame(2026, $summary['year']);
        // Three leaves, 12 days; only the internal leave's 3 days come out of the 21 (owner: not sick leave, nor a leave type added later).
        $this->assertSame(['count' => 3, 'days' => 12, 'balance' => 18], $summary['leaves']);
        $this->assertSame(1, $summary['courses']);
        $this->assertSame('20000.00', $summary['rewards_total']);
        $this->assertSame('2500.00', $summary['deductions_total']);
        $this->assertSame(['months' => 3, 'planned_days' => 62, 'attended_days' => 49, 'absent_days' => 13, 'absence_rate' => 0.2097], $summary['attendance']);
        $this->assertSame(['complaints' => 2, 'warnings' => 1, 'punishments' => 0, 'thanks' => 1, 'total' => 4], $summary['conduct']);
        $this->assertSame(['has_debts' => true, 'total_remaining' => '250000.00', 'open' => 2, 'overdue' => 1], $summary['debts']);

        // The report's lists: newest first; the last month of attendance.
        $this->assertCount(8, $summary['latest_movements']);
        $this->assertSame('2026-05-01', $summary['latest_movements'][0]['fields']['occurred_on']['value']);
        $this->assertSame('مكافأة مالية', $summary['latest_movements'][2]['fields']['movement_type_id']['display']);
        $this->assertCount(5, $summary['latest_conduct']);
        $this->assertSame('2026-07-01', $summary['latest_conduct'][0]['fields']['occurred_on']['value']);
        $this->assertSame(3, $summary['latest_attendance']['fields']['month']['value']);
        $this->assertTrue($summary['latest_attendance']['computed']['high_absence']);

        $last = $this->getJson(self::API."/employees/{$employee->id}/records/summary?year=2025")->json('data');
        $this->assertSame(['count' => 1, 'days' => 10, 'balance' => 11], $last['leaves']);
        $this->assertSame(1, $last['conduct']['punishments']);
        $this->assertEquals(1, $last['attendance']['absence_rate'], 'JSON writes 1.0 as 1');

        $empty = $this->getJson(self::API."/employees/{$other->id}/records/summary")->assertOk()->json('data');
        $this->assertEquals(['months' => 0, 'planned_days' => 0, 'attended_days' => 0, 'absent_days' => 0, 'absence_rate' => 0], $empty['attendance']);
        $this->assertNull($empty['latest_attendance']);
    }

    public function test_a_removed_record_counts_for_nothing(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/debts', ['employee_id' => $employee->id, 'values' => ['amount' => 1000]])->assertCreated();
        $debt = $this->getJson(self::API.'/records/debts')->json('data.0.id');

        $this->deleteJson(self::API."/records/debts/{$debt}", ['reason' => 'دين مكرر'])->assertCreated()->assertJsonPath('data.status', 'approved');

        $this->getJson(self::API."/employees/{$employee->id}/records/summary")->assertJsonPath('data.debts.has_debts', false);
        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.computed.total_remaining', '0.00');
    }
}
