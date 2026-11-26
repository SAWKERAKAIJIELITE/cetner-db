<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Enums\StaffRole;
use App\Support\BusinessDay;
use Tests\TestCase;

/** D-051: the dashboards over the approved files and records. */
class DashboardTest extends TestCase
{
    private function tile(array $groups, string $key): mixed
    {
        foreach ($groups as $group) {
            foreach ($group['tiles'] as $tile) {
                if ($tile['key'] === $key) {
                    return $tile['value'];
                }
            }
        }

        $this->fail("No tile {$key}.");
    }

    private function record(string $type, string $employeeId, array $values): void
    {
        $this->postJson(self::API."/records/{$type}", ['employee_id' => $employeeId, 'values' => $values])->assertCreated();
    }

    public function test_the_dashboards_are_for_reports_view_only(): void
    {
        $this->actingAsStaff($this->staffUser());

        foreach (['reports/overview', 'reports/employees', 'reports/movements?year=2026', 'reports/overview/contracts-ending'] as $path) {
            $this->getJson(self::API.'/'.$path)->assertForbidden();
        }

        $this->actingAsStaff($this->staffUser(StaffRole::GeneralManager))->getJson(self::API.'/reports/overview')->assertOk();
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->getJson(self::API.'/reports/employees')->assertOk();
    }

    public function test_the_overview_counts_the_files_of_a_unit_with_their_contracts_statuses_and_missing_fields(): void
    {
        $today = BusinessDay::today();
        $this->actingAsStaff($this->admin());
        $unit = $this->postJson(self::API.'/settings/org-units', ['name_ar' => 'القيادة'])->assertCreated()->json('data.id');
        $child = $this->postJson(self::API.'/settings/org-units', ['name_ar' => 'الإدارة', 'parent_id' => $unit])->assertCreated()->json('data.id');
        $other = $this->postJson(self::API.'/settings/org-units', ['name_ar' => 'الفرع'])->assertCreated()->json('data.id');
        $active = $this->itemId('employee_status', 'active');
        $fled = $this->itemId('job_status', 'fled');

        $soon = $this->employeeFile(['org_unit_id' => $child, 'employee_status_id' => $active, 'job_status_id' => $fled,
            'contract_starts_on' => $today->subYear()->format('Y-m-d'), 'contract_ends_on' => $today->addDays(10)->format('Y-m-d')]);
        $this->employeeFile(['org_unit_id' => $unit, 'contract_starts_on' => $today->subYears(2)->format('Y-m-d'), 'contract_ends_on' => $today->subDay()->format('Y-m-d')]);
        $this->employeeFile(['org_unit_id' => $other, 'contract_starts_on' => $today->format('Y-m-d'), 'contract_ends_on' => $today->addYear()->format('Y-m-d')]);
        $this->actingAsStaff($this->admin());
        $this->record('debts', $soon->id, ['amount' => 500]);
        $this->postJson(self::API."/employees/{$soon->id}/reviews", ['cycle' => 'monthly', 'values' => ['residence_city' => 'دمشق']])->assertCreated();

        $all = $this->getJson(self::API.'/reports/overview')->assertOk()->json('data');
        $this->assertSame(3, $all['total']);
        $this->assertSame(['valid' => 1, 'ending' => 1, 'expired' => 1, 'no_end_date' => 0], $all['contracts']);
        $tiles = collect($all['tiles'])->pluck('value', 'key');
        $this->assertSame(1, $tiles['active']);
        $this->assertSame(2, $tiles['monthly_overdue']);
        $this->assertSame(1, $tiles['contracts_ending']);
        $this->assertSame(1, $tiles['with_debts']);
        $fledRow = collect($all['job_statuses'])->firstWhere('item.id', $fled);
        $this->assertSame(1, $fledRow['count']);
        $this->assertEquals(33.33, $fledRow['share']);
        $this->assertSame(2, collect($all['job_statuses'])->firstWhere('item', null)['count']);
        $this->assertCount(5, $all['missing_fields']);
        $this->assertSame(3, $all['missing_fields'][0]['count']);

        // A unit with the units under it, or alone.
        $this->getJson(self::API."/reports/overview?org_unit_id={$unit}")->assertJsonPath('data.total', 2);
        $this->getJson(self::API."/reports/overview?org_unit_id={$unit}&include_sub_units=0")->assertJsonPath('data.total', 1);
        $this->getJson(self::API.'/reports/overview?missing_limit=2')->assertJsonCount(2, 'data.missing_fields');

        $this->getJson(self::API.'/reports/overview/contracts-ending')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $soon->id)->assertJsonPath('data.0.days_left', 10)
            ->assertJsonPath('data.0.org_unit.id', $child);

        $overdue = $this->getJson(self::API.'/reports/overview/overdue-reviews?cycle=monthly')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertNotContains($soon->id, array_column($overdue, 'id'));
        $this->assertSame(['monthly', 'quarterly', 'semi_annual', 'annual'], $overdue[0]['overdue_cycles']);
        $this->getJson(self::API.'/reports/overview/overdue-reviews')->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.cycles.0.last_reviewed_on', $today->format('Y-m-d'))
            ->assertJsonPath('data.2.overdue_cycles', ['quarterly', 'semi_annual', 'annual']);
    }

    public function test_the_employees_dashboard_counts_by_item_within_its_filters(): void
    {
        $male = $this->itemId('gender', 'male');
        $female = $this->itemId('gender', 'female');
        $married = $this->itemId('marital_status', 'متزوج');
        $studying = $this->itemId('current_study', 'طالب ماجستير');
        $notStudying = $this->itemId('current_study', 'none');
        $birth = BusinessDay::today()->subYears(30)->format('Y-m-d');

        $this->employeeFile(['gender_id' => $male, 'marital_status_id' => $married, 'current_study_id' => $studying, 'birth_date' => $birth,
            'base_salary' => '1000', 'quran_parts' => 30, 'military_rank' => 'ملازم', 'children_count' => 2]);
        $this->employeeFile(['gender_id' => $male, 'current_study_id' => $notStudying, 'base_salary' => '3000']);
        $this->employeeFile(['gender_id' => $female]);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));
        $data = $this->getJson(self::API.'/reports/employees')->assertOk()->json('data');
        $groups = $data['groups'];
        $this->assertSame(3, $data['total']);
        $this->assertSame(['general', 'job_status', 'contracts', 'personal', 'military', 'health_housing', 'education', 'schedule', 'finance', 'nature', 'quality', 'cycles'],
            array_column($groups, 'key'));
        $this->assertSame(2, $this->tile($groups, 'male'));
        $this->assertSame(1, $this->tile($groups, 'female'));
        $this->assertSame(1, $this->tile($groups, "marital_status.{$married}"));
        $this->assertSame(1, $this->tile($groups, 'studying'));
        $this->assertSame(1, $this->tile($groups, 'quran_hafiz'));
        $this->assertSame(1, $this->tile($groups, 'military_rank'));
        $this->assertSame(1, $this->tile($groups, 'with_children'));
        $this->assertEquals(30.0, $this->tile($groups, 'average_age'));
        $this->assertSame('4000.00', $this->tile($groups, 'salaries_total'));
        $this->assertSame('2000.00', $this->tile($groups, 'salary_average'));
        $this->assertSame('1000.00', $this->tile($groups, 'salary_min'));
        $this->assertSame(3, $this->tile($groups, 'without_portrait'));
        $this->assertSame(3, $this->tile($groups, 'overdue.monthly'));
        $this->assertEquals(0, $this->tile($groups, 'monthly_compliance'));

        // The filters, as the list's.
        $this->getJson(self::API."/reports/employees?gender_id={$male}")->assertJsonPath('data.total', 2);

        // A renamed item keeps its tile; an added one gets its own.
        $this->actingAsStaff($this->admin());
        $this->putJson(self::API."/settings/lists/marital_status/items/{$married}", ['name_ar' => 'متأهل'])->assertOk();
        $this->postJson(self::API.'/settings/lists/marital_status/items', ['name_ar' => 'منفصل'])->assertCreated();
        $personal = collect($this->getJson(self::API.'/reports/employees')->json('data.groups'))->firstWhere('key', 'personal')['tiles'];
        $this->assertSame('متأهل', collect($personal)->firstWhere('key', "marital_status.{$married}")['label']);
        $this->assertSame(0, collect($personal)->firstWhere('label', 'منفصل')['value']);
    }

    public function test_the_movements_dashboard_counts_the_period_and_the_chosen_employee(): void
    {
        $a = $this->employeeFile(['base_salary' => '1000']);
        $b = $this->employeeFile();
        $this->actingAsStaff($this->admin());

        $this->record('movements', $a->id, ['occurred_on' => '2026-03-01', 'movement_type_id' => $this->itemId('movement_type', 'leave_internal'), 'starts_on' => '2026-03-01', 'ends_on' => '2026-03-05']);
        $this->record('movements', $a->id, ['occurred_on' => '2026-04-01', 'movement_type_id' => $this->itemId('movement_type', 'leave_sick'), 'starts_on' => '2026-04-01', 'ends_on' => '2026-04-02']);
        $this->record('movements', $a->id, ['occurred_on' => '2026-03-10', 'movement_type_id' => $this->itemId('movement_type', 'reward'), 'amount' => 300]);
        $this->record('movements', $b->id, ['occurred_on' => '2026-03-11', 'movement_type_id' => $this->itemId('movement_type', 'deduction'), 'amount' => 100]);
        $this->record('movements', $b->id, ['occurred_on' => '2025-03-11', 'movement_type_id' => $this->itemId('movement_type', 'appointment')]);
        $this->record('conduct', $b->id, ['occurred_on' => '2026-03-12', 'conduct_type_id' => $this->itemId('conduct_type', 'warning')]);
        $this->record('attendance', $a->id, ['year' => 2026, 'month' => 3, 'planned_days' => 20, 'attended_days' => 18]);
        $this->record('attendance', $b->id, ['year' => 2026, 'month' => 4, 'planned_days' => 20, 'attended_days' => 20]);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));
        $this->getJson(self::API.'/reports/movements')->assertUnprocessable()->assertJsonValidationErrors('year');

        $year = $this->getJson(self::API.'/reports/movements?year=2026')->assertOk()->json('data');
        $this->assertSame(['year' => 2026, 'month' => null, 'from' => '2026-01-01', 'to' => '2026-12-31'], $year['period']);
        $groups = $year['groups'];
        $this->assertSame(5, $this->tile($groups, 'movements'));
        $this->assertSame('300.00', $this->tile($groups, 'rewards_total'));
        $this->assertSame(1, $this->tile($groups, 'leave.'.$this->itemId('movement_type', 'leave_internal')));
        $this->assertSame(1, $this->tile($groups, 'leave.'.$this->itemId('movement_type', 'leave_sick')));
        $this->assertSame(7, $this->tile($groups, 'leave_days'));
        $this->assertSame('200.00', $this->tile($groups, 'net'));
        $this->assertSame(0, $this->tile($groups, 'appointments'));
        $this->assertSame(1, $this->tile($groups, 'warnings'));
        $this->assertEquals(2, $this->tile($groups, 'absent_days'));
        $this->assertEquals(5, $this->tile($groups, 'absence_rate'));

        $march = $this->getJson(self::API."/reports/movements?year=2026&month=3&employee_id={$a->id}")->assertOk()->json('data.groups');
        $this->assertSame(5, $this->tile($march, 'leave_days'));
        $this->assertSame(1, $this->tile($march, 'employee.leaves'));
        $this->assertSame('300.00', $this->tile($march, 'employee.rewards_amount'));
        $this->assertEquals(10, $this->tile($march, 'employee.absence_rate'));
        $this->getJson(self::API.'/reports/movements?year=2026&employee_id=00000000-0000-0000-0000-000000000000')->assertJsonValidationErrors('employee_id');

        // One row per file: the leave balance is the year's, the rating the net's.
        $rows = $this->getJson(self::API.'/reports/movements/employees?year=2026&month=3&sort=net')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame($a->id, $rows[0]['id']);
        $this->assertSame(['positive', 'إيجابي'], [$rows[0]['rating'], $rows[0]['rating_label']]);
        $this->assertSame(1, $rows[0]['internal_leaves']);
        $this->assertSame(16, $rows[0]['leave_balance']);
        $this->assertSame('300.00', $rows[0]['net']);
        $this->assertEquals(10, $rows[0]['absence_rate']);
        $this->assertSame(['negative', 1], [$rows[1]['rating'], $rows[1]['warnings']]);
        $this->getJson(self::API.'/reports/movements/employees?year=2026&sort=net&direction=asc')->assertJsonPath('data.0.id', $b->id);
    }
}
