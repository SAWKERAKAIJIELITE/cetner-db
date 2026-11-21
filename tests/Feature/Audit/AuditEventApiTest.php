<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Support\AuditEvents;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class AuditEventApiTest extends TestCase
{
    public function test_a_row_is_written_with_actor_category_related_ids_and_address(): void
    {
        $admin = $this->admin();
        $event = AuditTrail::record('access.role_changed', $admin, ['staff_user_id' => 'abc', 'nested' => ['employee_ids' => ['e1', 'e2']]]);

        $this->assertSame('security', $event->category);
        $this->assertSame('{abc,e1,e2}', DB::table('audit_events')->where('id', $event->id)->value('related_ids'));
        $this->assertSame('127.0.0.1', DB::table('audit_events')->where('id', $event->id)->value('ip'));
    }

    public function test_a_rolled_back_action_leaves_no_row(): void
    {
        try {
            DB::transaction(function () {
                AuditTrail::record('auth.login', $this->admin());
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_the_log_is_append_only(): void
    {
        $event = AuditTrail::record('auth.login', $this->admin());

        foreach ([
            fn () => DB::table('audit_events')->where('id', $event->id)->update(['event' => 'auth.logout']),
            fn () => DB::table('audit_events')->where('id', $event->id)->delete(),
            fn () => DB::statement('TRUNCATE audit_events'),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('the audit log was changed');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
    }

    public function test_the_category_follows_the_event(): void
    {
        $this->expectException(QueryException::class);

        DB::table('audit_events')->insert(['id' => '01890000-0000-7000-8000-0000000000aa', 'event' => 'auth.login', 'category' => 'operations']);
    }

    public function test_the_general_manager_reads_everything_newest_first_with_filters(): void
    {
        $manager = $this->staffUser(StaffRole::GeneralManager);
        $admin = $this->admin();
        Carbon::setTestNow('2026-11-20 10:00:00');
        AuditTrail::record('access.user_created', $admin, ['staff_user_id' => 'u-1']);
        Carbon::setTestNow('2026-11-21 10:00:00');
        AuditTrail::record('access.role_changed', $admin, ['staff_user_id' => 'u-2']);
        AuditTrail::record('auth.login', $manager);

        $this->actingAsStaff($manager);
        $this->getJson(self::API.'/audit-events')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.event', 'auth.login')
            ->assertJsonPath('data.1.event_label', AuditEvents::label('access.role_changed'))
            ->assertJsonPath('data.1.actor.id', $admin->id);

        $this->getJson(self::API.'/audit-events?event=access.')->assertJsonCount(2, 'data');
        $this->getJson(self::API.'/audit-events?event=access.user_created')->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/audit-events?related_id=u-2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.event', 'access.role_changed');
        $this->getJson(self::API."/audit-events?actor_id={$manager->id}")->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/audit-events?from=2026-11-21&to=2026-11-21')->assertJsonCount(2, 'data');
        $this->getJson(self::API.'/audit-events?to=2026-11-20')->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/audit-events?event=bad%25')->assertUnprocessable();
    }

    public function test_data_entry_reads_the_operations_only(): void
    {
        AuditTrail::record('auth.login', $this->admin());

        $this->actingAsStaff($this->staffUser())->getJson(self::API.'/audit-events')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/audit-events/events')->assertOk()->assertJsonCount(
            collect(AuditEvents::LABELS)->keys()->filter(fn ($e) => AuditTrail::categoryOf($e) === 'operations')->count(), 'data');
    }

    public function test_reading_the_log_needs_an_audit_permission(): void
    {
        $user = $this->staffUser();
        Role::query()->where('slug', 'data_entry')->first()->permissions()->detach(
            Permission::query()->where('slug', 'audit.view_related')->value('id'));

        $this->actingAsStaff($user->refresh())->getJson(self::API.'/audit-events')->assertForbidden();
    }

    public function test_every_recorded_event_has_an_arabic_label_and_every_label_is_used(): void
    {
        $recorded = [];

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            preg_match_all("/AuditTrail::record\\(\\s*'([a-z_.]+)'/", $file->getContents(), $matches);
            $recorded = array_merge($recorded, $matches[1]);
        }

        $recorded = array_values(array_unique($recorded));
        sort($recorded);
        $labelled = array_keys(AuditEvents::LABELS);
        sort($labelled);

        $this->assertSame($labelled, $recorded);
    }
}
