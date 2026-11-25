<?php

namespace Tests\Feature\Employees;

use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** D-036: the workbook's «آخر مراجعة» columns as confirmations per cycle and period. */
class CycleReviewTest extends TestCase
{
    public function test_a_cycle_is_confirmed_once_per_period_and_counts_when_approved(): void
    {
        Carbon::setTestNow('2026-11-23 09:00:00');
        $employee = $this->employeeFile();

        $this->actingAsStaff($this->staffUser())->getJson(self::API."/employees/{$employee->id}")
            ->assertJsonPath('data.reviews.monthly.overdue', true)
            ->assertJsonPath('data.reviews.monthly.period', '2026-11')
            ->assertJsonFragment(['code' => 'review_overdue']);

        $set = $this->postJson(self::API."/employees/{$employee->id}/reviews", ['cycle' => 'monthly', 'values' => ['residence_city' => 'دمشق']])
            ->assertCreated()->assertJsonPath('data.kind', 'review')->assertJsonPath('data.period', '2026-11')
            ->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->postJson(self::API."/employees/{$employee->id}/reviews", ['cycle' => 'monthly'])->assertUnprocessable()->assertJsonValidationErrors('cycle');
        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.reviews.monthly.overdue', true);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/employee-changes/{$set}/approve")->assertOk();

        $this->getJson(self::API."/employees/{$employee->id}")
            ->assertJsonPath('data.reviews.monthly.overdue', false)
            ->assertJsonPath('data.reviews.monthly.last_reviewed_on', '2026-11-23')
            ->assertJsonPath('data.reviews.monthly.due_on', '2026-12-28')
            ->assertJsonPath('data.reviews.monthly.current_period_done', true)
            ->assertJsonPath('data.fields.residence_city.value', 'دمشق');

        $this->getJson(self::API."/employees/{$employee->id}/reviews")->assertOk()->assertJsonCount(1, 'data.history')
            ->assertJsonPath('data.history.0.period', '2026-11');
    }

    public function test_a_confirmation_without_changes_and_only_the_cycles_fields(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));

        $this->postJson(self::API."/employees/{$employee->id}/reviews", ['cycle' => 'quarterly', 'values' => ['residence_city' => 'دمشق']])
            ->assertUnprocessable()->assertJsonValidationErrors('values');

        // The supervisor confirms: applied at once, nothing changed.
        $this->postJson(self::API."/employees/{$employee->id}/reviews", ['cycle' => 'quarterly'])->assertCreated()
            ->assertJsonPath('data.status', 'approved')->assertJsonCount(0, 'data.items');
        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.reviews.quarterly.overdue', false);
    }

    public function test_a_confirmation_older_than_the_window_is_overdue_again(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/employees/{$employee->id}/reviews", ['cycle' => 'monthly'])->assertCreated();

        Carbon::setTestNow('2026-10-05 09:00:00'); // 34 days: within 35
        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.reviews.monthly.overdue', false);
        $this->getJson(self::API.'/employees?overdue_cycle=monthly')->assertJsonCount(0, 'data');

        Carbon::setTestNow('2026-10-07 09:00:00'); // 36 days
        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.reviews.monthly.overdue', true)
            ->assertJsonPath('data.reviews.monthly.period', '2026-10')->assertJsonPath('data.reviews.monthly.current_period_done', false);
        $this->getJson(self::API.'/employees?overdue_cycle=monthly')->assertJsonCount(1, 'data');
    }

    public function test_the_periods(): void
    {
        $employee = $this->employeeFile();
        Carbon::setTestNow('2026-05-10 09:00:00');
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));

        foreach (['monthly' => '2026-05', 'quarterly' => '2026-Q2', 'semi_annual' => '2026-H1', 'annual' => '2026'] as $cycle => $period) {
            $this->postJson(self::API."/employees/{$employee->id}/reviews", ['cycle' => $cycle])->assertJsonPath('data.period', $period);
        }
    }
}
