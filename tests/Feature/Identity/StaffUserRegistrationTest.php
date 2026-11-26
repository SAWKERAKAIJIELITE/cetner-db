<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffUserRegistrationTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'username' => '  Omar.Entry ',
            'first_name' => 'عمر',
            'last_name' => 'الخطيب',
            'personal_number' => '١٢٣٤٥٦',
            'role' => 'data_entry',
            'password' => 'Strong1!pw',
            'password_confirmation' => 'Strong1!pw',
        ], $overrides);
    }

    public function test_an_administrator_adds_a_data_entry_user(): void
    {
        $admin = $this->admin();

        $this->actingAsStaff($admin)->postJson(self::API.'/new', $this->payload())->assertCreated()
            ->assertJsonPath('data.username', 'omar.entry')
            ->assertJsonPath('data.personal_number', '123456')
            ->assertJsonPath('data.role.slug', 'data_entry')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $user = StaffUser::query()->where('username', 'omar.entry')->sole();
        $this->assertTrue(Hash::check('Strong1!pw', $user->password));
        $this->assertSame($admin->id, $user->role_assigned_by);
        $this->assertDatabaseHas('audit_events', ['event' => 'access.user_created', 'actor_id' => $admin->id]);
    }

    public function test_a_user_created_inactive_has_a_reason(): void
    {
        $this->actingAsStaff($this->admin())->postJson(self::API.'/new', $this->payload(['is_active' => false]))->assertCreated()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.deactivation_reason', __('identity.created_inactive'));
    }

    public function test_the_rules_are_checked_with_arabic_messages_and_field_names(): void
    {
        $this->actingAsStaff($this->admin());

        $response = $this->postJson(self::API.'/new', $this->payload([
            'username' => 'عمر',
            'first_name' => 'ع1',
            'personal_number' => '12',
            'password' => 'weak',
            'password_confirmation' => 'other',
            'role' => 'no_such_role',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['username', 'first_name', 'personal_number', 'password', 'role']);

        $this->assertSame(__('validation.custom.username.regex'), $response->json('errors.username.0'));
        $this->assertStringContainsString('كلمة المرور', $response->json('errors.password.0'));
        $this->assertStringContainsString('الدور', $response->json('errors.role.0'));
    }

    public function test_username_and_personal_number_are_unique(): void
    {
        $this->staffUser(attributes: ['username' => 'omar.entry', 'personal_number' => '123456']);

        $this->actingAsStaff($this->admin())->postJson(self::API.'/new', $this->payload())->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'personal_number']);
    }

    public function test_the_database_refuses_a_bad_username_whatever_the_code(): void
    {
        $this->expectException(QueryException::class);

        DB::table('staff_users')->insert([
            'id' => '01890000-0000-7000-8000-000000000001',
            'personal_number' => '9999',
            'username' => 'Has Space',
            'first_name' => 'اسم',
            'last_name' => 'كنية',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_inactive_user_without_a_reason_is_refused_by_the_database(): void
    {
        $user = $this->staffUser();

        $this->expectException(QueryException::class);
        DB::table('staff_users')->where('id', $user->id)->update(['is_active' => false]);
    }

    public function test_staff_users_are_added_at_the_hiring_systems_path(): void
    {
        $this->actingAsStaff($this->admin());
        $this->postJson(self::API.'/users', [])->assertMethodNotAllowed();
        $this->postJson(self::API.'/new', [])->assertUnprocessable()->assertJsonValidationErrors(['username', 'first_name', 'last_name', 'personal_number', 'role', 'password']);
    }
}
