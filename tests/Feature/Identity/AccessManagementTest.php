<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\AccessToken;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Tests\TestCase;

class AccessManagementTest extends TestCase
{
    public function test_each_role_holds_its_defaults_and_the_administrator_holds_everything(): void
    {
        $entry = $this->staffUser(StaffRole::DataEntry);
        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $manager = $this->staffUser(StaffRole::GeneralManager);
        $admin = $this->admin();

        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::ROLES['data_entry'], $entry->permissionSlugs());
        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::ROLES['supervisor'], $supervisor->permissionSlugs());
        $this->assertEqualsCanonicalizing(RolesAndPermissionsSeeder::ROLES['general_manager'], $manager->permissionSlugs());
        $this->assertEqualsCanonicalizing(array_keys(RolesAndPermissionsSeeder::PERMISSIONS), $admin->permissionSlugs());
        $this->assertSame(0, Role::query()->where('slug', 'system_admin')->first()->permissions()->count());

        $readOnly = Permission::query()->where('read_only', true)->pluck('slug')->all();
        $this->assertEmpty(array_diff($manager->permissionSlugs(), $readOnly), 'the general manager views only');
        $this->assertNotContains('users.manage', $entry->permissionSlugs());
        $this->assertNotContains('settings.manage', $entry->permissionSlugs());
        $this->assertNotContains('reports.view', $entry->permissionSlugs());
        // OD-2: the settings are the administrator's and the supervisor's; OD-1: the supervisor reviews, data entry does not.
        $this->assertContains('settings.manage', $supervisor->permissionSlugs());
        $this->assertContains('entries.review', $supervisor->permissionSlugs());
        $this->assertNotContains('entries.review', $entry->permissionSlugs());
        // The supervisor reads, updates and reviews; adds and deletes nothing (D-023).
        $this->assertContains('employees.update', $supervisor->permissionSlugs());
        $this->assertContains('records.update', $supervisor->permissionSlugs());
        foreach (['employees.create', 'records.create', 'records.delete', 'documents.manage', 'imports.manage'] as $permission) {
            $this->assertNotContains($permission, $supervisor->permissionSlugs());
        }
        $this->assertContains('employees.create', $entry->permissionSlugs());
        $this->assertContains('records.delete', $entry->permissionSlugs());
        $this->assertNotContains('users.manage', $supervisor->permissionSlugs());
    }

    public function test_a_user_without_a_role_holds_nothing(): void
    {
        $user = $this->staffUser();
        $user->forceFill(['role_id' => null, 'role_assigned_at' => null])->save();

        $this->assertSame([], $user->refresh()->permissionSlugs());
        $this->assertFalse($user->can('employees.view'));
    }

    public function test_the_catalogue_and_roles_are_listed(): void
    {
        $this->actingAsStaff($this->admin());

        $this->getJson(self::API.'/permissions')->assertOk()->assertJsonCount(count(RolesAndPermissionsSeeder::PERMISSIONS), 'data')
            ->assertJsonFragment(['slug' => 'employees.view', 'read_only' => true]);

        $this->getJson(self::API.'/roles')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonFragment(['slug' => 'system_admin', 'administrator' => true, 'users_count' => 1]);
    }

    public function test_permissions_are_added_to_and_removed_from_a_role(): void
    {
        $admin = $this->admin();
        $entry = $this->staffUser();
        $this->actingAsStaff($admin);

        $this->postJson(self::API.'/roles/data_entry/permissions', ['permissions' => ['settings.manage', 'reports.view']])->assertOk()
            ->assertJsonFragment(['slug' => 'data_entry']);
        $this->assertTrue($entry->refresh()->hasPermission('settings.manage'));
        $this->assertDatabaseHas('audit_events', ['event' => 'access.role_permissions_added', 'actor_id' => $admin->id]);

        $this->deleteJson(self::API.'/roles/data_entry/permissions/settings.manage')->assertOk();
        $this->assertFalse($entry->refresh()->hasPermission('settings.manage'));
        $this->deleteJson(self::API.'/roles/data_entry/permissions/settings.manage')->assertNotFound();
        $this->deleteJson(self::API.'/roles/data_entry/permissions/no.such')->assertNotFound();
    }

    public function test_the_administrators_role_is_not_edited_and_the_managers_takes_read_only_permissions(): void
    {
        $this->actingAsStaff($this->admin());

        $this->postJson(self::API.'/roles/system_admin/permissions', ['permissions' => ['employees.view']])->assertUnprocessable()
            ->assertJsonPath('errors.role.0', __('identity.admin_role_fixed'));

        $this->postJson(self::API.'/roles/general_manager/permissions', ['permissions' => ['employees.update']])->assertUnprocessable()
            ->assertJsonValidationErrors('permissions');

        $this->postJson(self::API.'/roles/general_manager/permissions', ['permissions' => ['audit.view_related']])->assertOk();

        $this->postJson(self::API.'/roles/data_entry/permissions', ['permissions' => ['no.such']])->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_the_seeder_does_not_undo_the_administrators_changes(): void
    {
        $this->actingAsStaff($this->admin());
        $this->deleteJson(self::API.'/roles/data_entry/permissions/imports.manage')->assertOk();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(Role::query()->where('slug', 'data_entry')->first()->permissions->contains('slug', 'imports.manage'));
    }

    public function test_a_users_one_role_is_changed_and_audited(): void
    {
        $admin = $this->admin();
        $user = $this->staffUser();
        $this->actingAsStaff($admin);

        $this->putJson(self::API."/users/{$user->id}/role", ['role' => 'general_manager'])->assertOk()
            ->assertJsonPath('data.role.slug', 'general_manager')->assertJsonPath('data.permissions', fn ($p) => ! in_array('employees.update', $p, true));

        $this->assertDatabaseHas('audit_events', ['event' => 'access.role_changed', 'actor_id' => $admin->id]);
    }

    public function test_the_last_user_manager_is_kept(): void
    {
        $admin = $this->admin();
        $this->actingAsStaff($admin);

        $this->putJson(self::API."/users/{$admin->id}/role", ['role' => 'data_entry'])->assertUnprocessable()
            ->assertJsonPath('errors.role.0', __('identity.last_user_manager'));
        $this->assertSame('system_admin', $admin->refresh()->role->slug);

        $second = $this->admin();
        $this->putJson(self::API."/users/{$admin->id}/role", ['role' => 'data_entry'])->assertOk();
        $this->assertTrue($second->refresh()->hasPermission('users.manage'));
    }

    public function test_only_the_administrator_gives_the_administrators_role(): void
    {
        $this->admin();
        $this->postJson(self::API.'/roles/data_entry/permissions', ['permissions' => ['users.manage']])->assertUnauthorized();

        $this->actingAsStaff($this->admin())->postJson(self::API.'/roles/data_entry/permissions', ['permissions' => ['users.manage']])->assertOk();

        $entry = $this->staffUser();
        $target = $this->staffUser();
        $this->actingAsStaff($entry->refresh());

        $this->putJson(self::API."/users/{$target->id}/role", ['role' => 'system_admin'])->assertForbidden()
            ->assertExactJson(['message' => __('identity.admin_role_by_admin_only')]);
        $this->putJson(self::API."/users/{$target->id}/role", ['role' => 'general_manager'])->assertOk();
    }

    public function test_deactivating_ends_every_session_and_reactivating_restores_the_account(): void
    {
        $admin = $this->admin();
        $user = $this->staffUser();
        $this->loginThrough($this->approvedDevice($user), $user->username)->assertOk();
        $this->actingAsStaff($admin);

        $this->postJson(self::API."/users/{$user->id}/deactivate", ['reason' => 'انتهاء العقد'])->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.deactivation_reason', 'انتهاء العقد');
        $this->assertSame(0, AccessToken::query()->where('tokenable_id', $user->id)->count());

        $this->postJson(self::API."/users/{$user->id}/deactivate", ['reason' => 'مرة ثانية'])->assertUnprocessable();
        $this->postJson(self::API."/users/{$admin->id}/deactivate", ['reason' => 'نفسي'])->assertUnprocessable()
            ->assertJsonPath('errors.user.0', __('identity.cannot_deactivate_self'));

        $this->postJson(self::API."/users/{$user->id}/activate")->assertOk()->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.deactivation_reason', null);

        $this->assertDatabaseHas('audit_events', ['event' => 'access.user_deactivated']);
        $this->assertDatabaseHas('audit_events', ['event' => 'access.user_activated']);
    }

    public function test_a_new_password_signs_the_user_out_and_keeps_the_device(): void
    {
        $user = $this->staffUser();
        $device = $this->approvedDevice($user);
        $this->loginThrough($device, $user->username)->assertOk();

        $this->actingAsStaff($this->admin())->putJson(self::API."/users/{$user->id}/password", [
            'password' => 'NewPass2@',
            'password_confirmation' => 'NewPass2@',
        ])->assertOk();

        $this->assertSame(0, AccessToken::query()->where('tokenable_id', $user->id)->count());
        $this->assertTrue($device->refresh()->isApproved());
        $this->loginThrough($device, $user->username, password: 'NewPass2@')->assertOk();
        $this->assertDatabaseHas('audit_events', ['event' => 'access.password_reset']);
    }

    public function test_users_are_listed_and_filtered(): void
    {
        $admin = $this->admin();
        $this->staffUser(attributes: ['username' => 'sara.k', 'first_name' => 'سارة', 'personal_number' => '555123']);
        $this->staffUser(StaffRole::GeneralManager);
        $this->staffUser(attributes: ['is_active' => false]);
        $this->actingAsStaff($admin);

        $this->getJson(self::API.'/users')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson(self::API.'/users?q=سارة')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', 'sara.k');
        $this->getJson(self::API.'/users?q=٥٥٥')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/users?role=general_manager')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/users?is_active=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::API."/users/{$admin->id}")->assertOk()->assertJsonPath('data.permissions', fn ($p) => in_array('users.manage', $p, true));
    }

    public function test_data_entry_and_the_general_manager_do_not_manage_access(): void
    {
        $target = $this->staffUser();

        foreach ([StaffRole::DataEntry, StaffRole::Supervisor, StaffRole::GeneralManager] as $role) {
            $this->actingAsStaff($this->staffUser($role));
            $this->getJson(self::API.'/users')->assertForbidden();
            $this->getJson(self::API.'/roles')->assertForbidden();
            $this->putJson(self::API."/users/{$target->id}/role", ['role' => 'system_admin'])->assertForbidden();
        }
    }
}
