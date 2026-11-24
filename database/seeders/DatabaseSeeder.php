<?php

namespace Database\Seeders;

use App\Modules\Employees\Database\Seeders\FieldDefinitionsSeeder;
use App\Modules\Identity\Database\Seeders\DevUsersSeeder;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Settings\Database\Seeders\DevSettingsSeeder;
use App\Modules\Settings\Database\Seeders\SettingsSeeder;
use Illuminate\Database\Seeder;

/**
 * Every environment: the catalogues (roles and permissions, the lists, the field definitions). Local only: demo accounts, the
 * workbook's sample structure and holidays.
 * Re-run safe.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SettingsSeeder::class);
        $this->call(FieldDefinitionsSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevUsersSeeder::class);
            $this->call(DevSettingsSeeder::class);
        }
    }
}
