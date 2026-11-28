<?php

namespace Tests;

use App\Modules\Employees\Support\FieldDefinitions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\StaffTestHelpers;

/** Every test runs on PostgreSQL (`hr_test`, D-002), with the role and permission catalogue seeded. */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
    use StaffTestHelpers;

    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // The field guide is read once per request (FieldDefinitions); a test's rolled-back change must not stay.
        FieldDefinitions::forget();
    }
}
