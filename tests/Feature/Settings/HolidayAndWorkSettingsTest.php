<?php

namespace Tests\Feature\Settings;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Settings\Models\WorkSettings;
use Tests\TestCase;

class HolidayAndWorkSettingsTest extends TestCase
{
    public function test_a_holiday_counts_both_ends_and_is_listed_by_year(): void
    {
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));

        $id = $this->postJson(self::API.'/settings/holidays', ['name' => 'عيد الفطر', 'starts_on' => '2026-03-20', 'ends_on' => '2026-03-25'])
            ->assertCreated()->assertJsonPath('data.days', 6)->json('data.id');
        $this->postJson(self::API.'/settings/holidays', ['name' => 'رأس السنة', 'starts_on' => '2026-12-31', 'ends_on' => '2027-01-01'])->assertCreated();
        $this->postJson(self::API.'/settings/holidays', ['name' => 'يوم آخر', 'starts_on' => '2027-05-01', 'ends_on' => '2027-05-01'])->assertCreated()
            ->assertJsonPath('data.days', 1);

        $this->getJson(self::API.'/settings/holidays?year=2026')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson(self::API.'/settings/holidays?year=2027')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.holiday_added']);
    }

    public function test_a_holiday_is_corrected_and_removed(): void
    {
        $this->actingAsStaff($this->admin());
        $id = $this->postJson(self::API.'/settings/holidays', ['name' => 'عيد الأضحى', 'starts_on' => '2026-05-27', 'ends_on' => '2026-06-02'])->json('data.id');

        $this->putJson(self::API."/settings/holidays/{$id}", ['ends_on' => '2026-05-31'])->assertOk()->assertJsonPath('data.days', 5);
        $this->putJson(self::API."/settings/holidays/{$id}", ['starts_on' => '2026-06-01'])->assertUnprocessable()
            ->assertJsonPath('errors.ends_on.0', __('settings.holiday_ends_before_start'));

        $this->deleteJson(self::API."/settings/holidays/{$id}")->assertNoContent();
        $this->deleteJson(self::API."/settings/holidays/{$id}")->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.holiday_updated']);
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.holiday_deleted']);
    }

    public function test_a_holiday_is_checked(): void
    {
        $this->actingAsStaff($this->admin());

        $this->postJson(self::API.'/settings/holidays', ['name' => 'خطأ', 'starts_on' => '2026-05-02', 'ends_on' => '2026-05-01'])
            ->assertUnprocessable()->assertJsonPath('errors.ends_on.0', __('settings.holiday_ends_before_start'));
        $this->postJson(self::API.'/settings/holidays', ['name' => 'طويلة', 'starts_on' => '2026-01-01', 'ends_on' => '2027-01-02'])
            ->assertUnprocessable()->assertJsonPath('errors.ends_on.0', __('settings.holiday_too_long'));
        $this->postJson(self::API.'/settings/holidays', ['name' => ' ', 'starts_on' => '01/05/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'starts_on', 'ends_on']);
    }

    public function test_the_workbooks_branch_settings_are_there_from_the_start(): void
    {
        $this->actingAsStaff($this->staffUser())->getJson(self::API.'/settings/work')->assertOk()->assertExactJson(['data' => [
            'branch_name' => 'القوى البشرية',
            'daily_hours' => 8,
            'starts_at' => '08:00',
            'ends_at' => '16:00',
            'late_after_minutes' => 10,
            'work_days_per_week' => 5,
            'weekly_days_off' => [5, 6],
            // «الأحد - الخميس»
            'working_days' => [7, 1, 2, 3, 4],
            'currency' => 'ل.س',
            'annual_leave_days' => 21,
            'high_absence_rate' => 20,
            'review_windows' => ['monthly' => 35, 'quarterly' => 100, 'semi_annual' => 190, 'annual' => 370],
            'updated_at' => WorkSettings::current()->updated_at->toIso8601String(),
        ]]);
    }

    public function test_the_branch_settings_change_with_their_rules_and_each_change_is_audited(): void
    {
        $admin = $this->admin();
        $this->actingAsStaff($admin);

        // The daily hours are the day's end less its start: computed, never sent.
        $this->putJson(self::API.'/settings/work', ['ends_at' => '15:30', 'starts_at' => '08:00', 'currency' => 'USD', 'high_absence_rate' => 10])->assertOk()
            ->assertJsonPath('data.daily_hours', 7.5)->assertJsonPath('data.ends_at', '15:30')->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.high_absence_rate', 10);
        $this->putJson(self::API.'/settings/work', ['daily_hours' => 6])->assertUnprocessable()->assertJsonValidationErrors('daily_hours');

        $event = AuditEvent::query()->where('event', 'settings.work_updated')->sole();
        $this->assertEqualsCanonicalizing(['ends_at', 'currency', 'high_absence_rate'], array_keys($event->context['changes']));

        $this->putJson(self::API.'/settings/work', ['ends_at' => '07:00'])->assertUnprocessable()
            ->assertJsonPath('errors.ends_at.0', __('settings.work_ends_before_start'));
        $this->putJson(self::API.'/settings/work', ['review_window_quarterly' => 20])->assertUnprocessable()
            ->assertJsonPath('errors.review_window_quarterly.0', __('settings.review_windows_order'));
        $this->putJson(self::API.'/settings/work', ['work_days_per_week' => 8, 'high_absence_rate' => 0, 'starts_at' => '8am'])->assertUnprocessable()
            ->assertJsonValidationErrors(['work_days_per_week', 'high_absence_rate', 'starts_at']);

        // The days off follow the working days: changed together, as many as the week has without work.
        $this->putJson(self::API.'/settings/work', ['work_days_per_week' => 6])->assertUnprocessable()
            ->assertJsonPath('errors.weekly_days_off.0', __('settings.days_off_count', ['count' => 1]));
        $this->putJson(self::API.'/settings/work', ['weekly_days_off' => [5, 5]])->assertUnprocessable()->assertJsonValidationErrors('weekly_days_off.1');
        $this->putJson(self::API.'/settings/work', ['work_days_per_week' => 6, 'weekly_days_off' => [5]])->assertOk()
            ->assertJsonPath('data.weekly_days_off', [5]);
        $this->putJson(self::API.'/settings/work', ['work_days_per_week' => 5, 'weekly_days_off' => [6, 5]])->assertOk()
            ->assertJsonPath('data.weekly_days_off', [5, 6]);

        // Sending the same values changes nothing and records nothing.
        $this->putJson(self::API.'/settings/work', ['high_absence_rate' => 10.00, 'starts_at' => '08:00'])->assertOk();
        $this->putJson(self::API.'/settings/work', ['weekly_days_off' => [6, 5], 'work_days_per_week' => 5])->assertOk();
        $this->assertSame(3, AuditEvent::query()->where('event', 'settings.work_updated')->count());
    }

    public function test_only_settings_managers_change_the_branch_settings(): void
    {
        foreach ([StaffRole::DataEntry, StaffRole::GeneralManager] as $role) {
            $this->actingAsStaff($this->staffUser($role))->putJson(self::API.'/settings/work', ['currency' => 'USD'])->assertForbidden();
        }

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->putJson(self::API.'/settings/work', ['currency' => 'USD'])->assertOk();
    }
}
