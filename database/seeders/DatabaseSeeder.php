<?php

namespace Database\Seeders;

use App\Modules\Identity\Database\Seeders\DevUsersSeeder;
use App\Modules\Identity\Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;

/**
 * Every environment: the catalogue (roles and permissions; later the lists). Local only: demo accounts.
 * Re-run safe.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment('local')) {
            $this->call(DevUsersSeeder::class);
        }
    }
}
