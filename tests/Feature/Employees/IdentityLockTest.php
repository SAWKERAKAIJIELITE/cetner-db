<?php

namespace Tests\Feature\Employees;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\IdentityLock;
use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-034 (owner, 24 Nov): once the administrator approves a file's identity, its six identity fields
 * (الرقم الذاتي، الرقم الوطني، الاسم، الأب، الأم، العائلة) are never changed again, by anyone.
 * D-038: the keyed fingerprint proves they were not changed around the application.
 */
class IdentityLockTest extends TestCase
{
    private function dataEntryFile(): Employee
    {
        $set = $this->actingAsStaff($this->staffUser())->postJson(self::API.'/employees', ['values' => [
            'personal_number' => '44001', 'first_name' => 'ليلى', 'father_name' => 'سعيد', 'family_name' => 'الحسن', 'national_number' => '44444444444',
        ]])->json('data');
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/employee-changes/{$set['id']}/approve")->assertOk();

        return Employee::query()->findOrFail($set['employee']['id']);
    }

    public function test_a_locked_identity_is_changed_by_nobody(): void
    {
        $employee = $this->employeeFile(['national_number' => '12345678901']);
        $this->assertTrue(IdentityLock::isLocked($employee), 'the administrator added it: locked');

        foreach ([StaffRole::DataEntry, StaffRole::Supervisor, StaffRole::SystemAdmin] as $role) {
            $this->actingAsStaff($this->staffUser($role));

            foreach (['national_number' => '12345678902', 'first_name' => 'خالد', 'family_name' => 'الأحمد'] as $field => $value) {
                $this->patchJson(self::API."/employees/{$employee->id}", ['values' => [$field => $value]])->assertUnprocessable()
                    ->assertJsonValidationErrors('values');
            }
        }

        $this->getJson(self::API."/employees/{$employee->id}")
            ->assertJsonPath('data.identity.locked', true)
            ->assertJsonPath('data.fields.national_number.locked', true)
            ->assertJsonPath('data.fields.mother_name.locked', false)
            ->assertJsonPath('data.fields.birth_date.locked', false);

        $this->assertSame('12345678901', $employee->refresh()->national_number);
    }

    public function test_the_database_refuses_it_whatever_the_code_and_never_unlocks(): void
    {
        $employee = $this->employeeFile();

        foreach ([
            fn () => DB::table('employees')->where('id', $employee->id)->update(['national_number' => '99999999999']),
            fn () => DB::table('employees')->where('id', $employee->id)->update(['first_name' => 'خالد']),
            fn () => DB::table('employees')->where('id', $employee->id)->update(['identity_locked_at' => null, 'identity_locked_by' => null, 'identity_hash' => null]),
            fn () => DB::table('employees')->where('id', $employee->id)->update(['identity_hash' => str_repeat('a', 64)]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('a locked identity was changed in the database');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Other fields stay changeable.
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->patchJson(self::API."/employees/{$employee->id}", ['values' => ['birth_date' => '1990-01-01', 'gender_id' => $this->itemId('gender', 'male')]])
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.needs_identity_review', false);
    }

    public function test_an_empty_identity_field_is_filled_once_by_the_administrators_approval(): void
    {
        $employee = $this->employeeFile();
        $before = $employee->identity_hash;

        $set = $this->actingAsStaff($this->staffUser())->patchJson(self::API."/employees/{$employee->id}", ['values' => ['mother_name' => 'مريم']])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.needs_identity_review', true)->json('data.id');

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/employee-changes/{$set}/approve")->assertForbidden();
        $this->actingAsStaff($this->admin())->postJson(self::API."/employee-changes/{$set}/approve")->assertOk();

        $employee->refresh();
        $this->assertSame('مريم', $employee->mother_name);
        $this->assertNotSame($before, $employee->identity_hash);
        $this->assertTrue(IdentityLock::intact($employee));

        $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['mother_name' => 'مريم أحمد']])->assertUnprocessable();
    }

    public function test_the_supervisors_approval_does_not_lock_and_the_administrator_locks_after(): void
    {
        $employee = $this->dataEntryFile();
        $this->assertFalse(IdentityLock::isLocked($employee));

        // Before the lock, an identity correction is possible, and goes to the administrator.
        $set = $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->patchJson(self::API."/employees/{$employee->id}", ['values' => ['family_name' => 'الحسني']])
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.needs_identity_review', true)->json('data.id');

        $this->actingAsStaff($this->admin())->getJson(self::API.'/employees?identity_locked=0')->assertJsonCount(1, 'data');
        $this->postJson(self::API."/employees/{$employee->id}/identity/lock")->assertUnprocessable()
            ->assertJsonPath('errors.employee.0', __('employees.identity_lock_pending_changes'));

        // Approving an identity change locks it.
        $this->postJson(self::API."/employee-changes/{$set}/approve")->assertOk();
        $this->assertTrue(IdentityLock::isLocked($employee->refresh()));
        $this->assertSame('الحسني', $employee->family_name);
        $this->getJson(self::API.'/employees?identity_locked=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.identity_locked', true);
        $this->assertDatabaseHas('audit_events', ['event' => 'employees.identity_locked']);
    }

    public function test_the_lock_action_is_the_administrators_once(): void
    {
        $employee = $this->dataEntryFile();

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/employees/{$employee->id}/identity/lock")->assertForbidden();

        $admin = $this->admin();
        $this->actingAsStaff($admin)->postJson(self::API."/employees/{$employee->id}/identity/lock")->assertOk()
            ->assertJsonPath('data.identity.locked', true)->assertJsonPath('data.identity.locked_by.id', $admin->id);
        $this->postJson(self::API."/employees/{$employee->id}/identity/lock")->assertUnprocessable()
            ->assertJsonPath('errors.employee.0', __('employees.identity_already_locked'));
    }

    public function test_a_change_waiting_when_the_identity_is_locked_is_refused_at_approval(): void
    {
        $employee = $this->dataEntryFile();
        $this->actingAsStaff($this->staffUser());
        $first = $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['family_name' => 'الحسني']])->json('data.id');
        $this->actingAsStaff($this->staffUser());
        $second = $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['father_name' => 'سعد', 'notes' => 'تصحيح']])->json('data.id');

        $this->actingAsStaff($this->admin());
        $this->postJson(self::API."/employee-changes/{$first}/approve")->assertOk();
        $this->assertTrue(IdentityLock::isLocked($employee->refresh()));

        $this->postJson(self::API."/employee-changes/{$second}/approve")->assertUnprocessable()->assertJsonValidationErrors('values');
        $this->assertSame('سعيد', $employee->refresh()->father_name);
        $this->postJson(self::API."/employee-changes/{$second}/reject", ['reason' => 'الهوية مثبتة'])->assertOk();
    }

    public function test_the_administrator_approving_a_new_file_locks_it(): void
    {
        $set = $this->actingAsStaff($this->staffUser())->postJson(self::API.'/employees', ['values' => [
            'personal_number' => '44002', 'national_number' => '44444444402', 'first_name' => 'هدى', 'father_name' => 'محمود', 'family_name' => 'السيد',
        ]])->json('data');

        $this->actingAsStaff($this->admin())->postJson(self::API."/employee-changes/{$set['id']}/approve")->assertOk();
        $this->assertTrue(IdentityLock::isLocked(Employee::query()->findOrFail($set['employee']['id'])));
    }

    public function test_the_fingerprint_catches_a_change_made_around_the_trigger(): void
    {
        $employee = $this->employeeFile(['national_number' => '12345678901']);
        $this->artisan('employees:verify-identity')->assertSuccessful();

        // Someone with database rights switches the trigger off and rewrites the national number.
        DB::statement('ALTER TABLE employees DISABLE TRIGGER employees_identity_locked');
        DB::table('employees')->where('id', $employee->id)->update(['national_number' => '98765432109']);
        DB::statement('ALTER TABLE employees ENABLE TRIGGER employees_identity_locked');

        $this->artisan('employees:verify-identity')->assertFailed();
        $this->assertDatabaseHas('audit_events', ['event' => 'employees.identity_check_failed']);
    }

    public function test_the_fingerprint_is_keyed_and_does_not_reveal_the_number(): void
    {
        $employee = $this->employeeFile(['national_number' => '12345678901']);
        $hash = $employee->identity_hash;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertNotSame(hash('sha256', '12345678901'), $hash);

        config(['hr.identity_hash_key' => 'another-key']);
        $this->assertNotSame($hash, IdentityLock::fingerprint($employee));
        $this->assertFalse(IdentityLock::intact($employee), 'a fingerprint is only checked with its own key');
    }
}
