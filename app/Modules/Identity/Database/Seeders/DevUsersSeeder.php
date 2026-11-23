<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Actions\RegisterStaffUser;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Seeder;

/** Local only: one account per role, password `Password1!` (never in production). */
class DevUsersSeeder extends Seeder
{
    public const PASSWORD = 'Password1!';

    private const USERS = [
        ['admin', 'مدير', 'النظام', '100001', StaffRole::SystemAdmin],
        ['manager', 'المدير', 'العام', '100002', StaffRole::GeneralManager],
        ['entry', 'مدخل', 'البيانات', '100003', StaffRole::DataEntry],
        ['supervisor', 'مشرف', 'الإدخال', '100005', StaffRole::Supervisor],
    ];

    public function run(RegisterStaffUser $action): void
    {
        foreach (self::USERS as [$username, $first, $last, $number, $role]) {
            if (StaffUser::query()->where('username', $username)->exists()) {
                continue;
            }

            $action->handle([
                'username' => $username,
                'first_name' => $first,
                'last_name' => $last,
                'personal_number' => $number,
                'password' => self::PASSWORD,
            ], Role::query()->where('slug', $role->value)->firstOrFail(), null, 'seeder');
        }
    }
}
