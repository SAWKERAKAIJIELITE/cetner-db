<?php

namespace Tests\Feature\Notifications;

use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** D-046: the bell and the sidebar counts, in the hiring system's shape. */
class NotificationTest extends TestCase
{
    public function test_a_refused_submission_goes_back_to_its_author_with_the_reason(): void
    {
        $employee = $this->employeeFile();
        $entry = $this->staffUser();
        $set = $this->actingAsStaff($entry)->patchJson(self::API."/employees/{$employee->id}", ['values' => ['notes' => 'x']])->json('data.id');
        $records = $this->postJson(self::API.'/records/debts', ['employee_id' => $employee->id, 'values' => ['amount' => 100]])->json('data.id');

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->postJson(self::API."/employee-changes/{$set}/reject", ['reason' => 'الملاحظة غير واضحة'])->assertOk();
        $this->travel(1)->seconds();
        $this->postJson(self::API."/record-changes/{$records}/reject", ['reason' => 'المبلغ ناقص'])->assertOk();
        $this->getJson(self::API.'/notifications')->assertJsonCount(0, 'data');

        $this->actingAsStaff($entry)->getJson(self::API.'/notifications/unread-count')->assertExactJson(['unread' => 2]);
        $list = $this->getJson(self::API.'/notifications')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kind', 'change_rejected')->assertJsonPath('data.0.details.area', 'records')
            ->assertJsonPath('data.0.details.record_type', 'debts')->assertJsonPath('data.0.details.reason', 'المبلغ ناقص')
            ->assertJsonPath('data.1.details.change_set_id', $set)->assertJsonPath('data.1.details.employee_id', $employee->id)
            ->assertJsonPath('data.1.read_at', null)->json('data');
        $this->assertStringContainsString('الملاحظة غير واضحة', $list[1]['message']);

        $this->postJson(self::API."/notifications/{$list[1]['id']}/read")->assertOk()->assertJsonPath('data.read_at', fn ($at) => $at !== null);
        $this->getJson(self::API.'/notifications?unread=1')->assertJsonCount(1, 'data');
        $this->postJson(self::API.'/notifications/read-all')->assertExactJson(['marked' => 1]);
        $this->getJson(self::API.'/badges')->assertJsonPath('data.notifications.unread', 0);

        // Nobody reads another's.
        $this->actingAsStaff($supervisor)->postJson(self::API."/notifications/{$list[0]['id']}/read")->assertNotFound();
    }

    public function test_the_sidebar_counts_follow_what_each_user_sees(): void
    {
        Carbon::setTestNow('2026-11-25 09:00:00');
        $employee = $this->employeeFile(['contract_ends_on' => '2026-12-10']);
        $this->employeeFile(['contract_ends_on' => '2027-06-01']);
        $entry = $this->staffUser();
        $this->actingAsStaff($entry)->patchJson(self::API."/employees/{$employee->id}", ['values' => ['notes' => 'x']])->assertCreated();
        $this->postJson(self::API.'/records/debts', ['employee_id' => $employee->id, 'values' => ['amount' => 100]])->assertCreated();

        $this->getJson(self::API.'/badges')->assertOk()
            ->assertJsonPath('data.reviews', null)->assertJsonPath('data.identity', null)
            ->assertJsonPath('data.mine.pending', 2)
            ->assertJsonPath('data.employees.contracts_ending_soon', 1)
            ->assertJsonPath('data.employees.monthly_overdue', 2);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->getJson(self::API.'/badges')
            ->assertJsonPath('data.reviews', ['employee_changes' => 1, 'record_changes' => 1, 'documents' => 0, 'imports' => 0, 'total' => 2])
            ->assertJsonPath('data.identity', null)->assertJsonPath('data.mine.pending', 0);

        $this->actingAsStaff($this->admin())->getJson(self::API.'/badges')
            ->assertJsonPath('data.identity', ['to_review' => 0, 'to_lock' => 0]);
    }

    public function test_a_contract_ending_within_thirty_days_is_told_once_to_those_who_update_files(): void
    {
        Carbon::setTestNow('2026-11-25 09:00:00');
        $this->employeeFile(['contract_ends_on' => '2026-12-20', 'first_name' => 'رنا']);
        $this->employeeFile(['contract_ends_on' => '2027-02-01']);
        $entry = $this->staffUser();
        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $manager = $this->staffUser(StaffRole::GeneralManager);

        $this->artisan('employees:remind-expiring-contracts')->assertSuccessful();
        $this->artisan('employees:remind-expiring-contracts')->assertSuccessful();

        $this->actingAsStaff($entry)->getJson(self::API.'/notifications')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'contract_ending_soon')->assertJsonPath('data.0.details.ends_on', '2026-12-20')
            ->assertJsonPath('data.0.details.days_left', 25);
        $this->actingAsStaff($supervisor)->getJson(self::API.'/notifications')->assertJsonCount(1, 'data');
        $this->actingAsStaff($manager)->getJson(self::API.'/notifications')->assertJsonCount(0, 'data');
        $this->actingAsStaff($this->admin())->getJson(self::API.'/notifications')->assertJsonCount(0, 'data');
    }
}
