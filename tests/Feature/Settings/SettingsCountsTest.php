<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Enums\StaffRole;
use Tests\TestCase;

/** The settings screens' counts (Step 4.1): «عدد المستخدمين» of a list item, «عدد الموظفين الفعّالين» of a unit. */
class SettingsCountsTest extends TestCase
{
    public function test_a_list_item_counts_the_files_and_records_using_it(): void
    {
        $male = $this->itemId('gender', 'male');
        $this->employeeFile(['gender_id' => $male]);
        $this->employeeFile(['gender_id' => $male]);
        $employee = $this->employeeFile();
        $reward = $this->itemId('movement_type', 'reward');
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => ['occurred_on' => '2026-01-01', 'movement_type_id' => $reward]])->assertCreated();

        $genders = collect($this->getJson(self::API.'/settings/lists/gender')->assertOk()->json('data.items'))->keyBy('code');
        $this->assertSame(2, $genders['male']['usage_count']);
        $this->assertSame(0, $genders['female']['usage_count']);

        $types = collect($this->getJson(self::API.'/settings/lists/movement_type')->json('data.items'))->keyBy('code');
        $this->assertSame(1, $types['reward']['usage_count']);
        $this->assertSame('leave_internal', $types['leave_internal']['code']);
        $this->getJson(self::API.'/settings/lists/payment_method')->assertOk()->assertJsonCount(2, 'data.items');
    }

    public function test_a_unit_counts_its_active_employees_with_the_units_under_it(): void
    {
        $this->actingAsStaff($this->admin());
        $top = $this->postJson(self::API.'/settings/org-units', ['name' => 'القيادة'])->json('data.id');
        $branch = $this->postJson(self::API.'/settings/org-units', ['name' => 'الفرع', 'parent_id' => $top])->json('data.id');
        $active = $this->itemId('employee_status', 'active');

        $this->employeeFile(['org_unit_id' => $top, 'employee_status_id' => $active]);
        $this->employeeFile(['org_unit_id' => $branch, 'employee_status_id' => $active]);
        $this->employeeFile(['org_unit_id' => $branch, 'employee_status_id' => $this->itemId('employee_status', 'inactive')]);
        $this->employeeFile(['org_unit_id' => $branch]);

        $units = collect($this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->getJson(self::API.'/settings/org-units')->json('data'))->keyBy('id');
        $this->assertSame(2, $units[$top]['active_employees']);
        $this->assertSame(1, $units[$top]['direct_active_employees']);
        $this->assertSame(1, $units[$branch]['active_employees']);
    }
}
