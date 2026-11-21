<?php

namespace Tests\Feature\Identity;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    public function test_the_first_administrator_is_created_from_the_shell(): void
    {
        $this->artisan('staff:create-admin', ['username' => 'Root.Admin', '--first-name' => 'مدير', '--last-name' => 'النظام', '--personal-number' => '1001'])
            ->expectsQuestion('Password', 'Strong1!pw')
            ->expectsQuestion('Password again', 'Strong1!pw')
            ->assertSuccessful();

        $admin = StaffUser::query()->where('username', 'root.admin')->sole();
        $this->assertSame('system_admin', $admin->role->slug);
        $this->assertTrue($admin->hasPermission('users.manage'));
        $this->assertDatabaseHas('audit_events', ['event' => 'access.user_created', 'actor_id' => null]);
        $this->assertSame('console', AuditEvent::query()->sole()->context['via']);
    }

    public function test_a_weak_or_mismatched_password_is_refused(): void
    {
        $this->artisan('staff:create-admin', ['username' => 'root.admin', '--first-name' => 'مدير', '--last-name' => 'النظام', '--personal-number' => '1001'])
            ->expectsQuestion('Password', 'weak')
            ->expectsQuestion('Password again', 'weaker')
            ->assertFailed();

        $this->assertSame(0, StaffUser::query()->count());
    }

    public function test_another_administrator_needs_a_confirmation(): void
    {
        $this->admin();

        $this->artisan('staff:create-admin', ['username' => 'second.admin'])
            ->expectsConfirmation('1 active administrator(s) exist already. Create another?', 'no')
            ->assertFailed();
    }

    public function test_it_needs_the_roles_seeded(): void
    {
        DB::table('permission_role')->delete();
        Role::query()->delete();

        $this->artisan('staff:create-admin', ['username' => 'root.admin'])->assertFailed();
    }
}
