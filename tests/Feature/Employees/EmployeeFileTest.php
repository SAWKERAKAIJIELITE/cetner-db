<?php

namespace Tests\Feature\Employees;

use App\Modules\Employees\Database\Seeders\FieldDefinitionsSeeder;
use App\Modules\Employees\Models\FieldDefinition;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** D-032, D-035, D-037: the fields, completeness, the workbook's formulas and warnings; the list. */
class EmployeeFileTest extends TestCase
{
    public function test_the_field_guide_is_served_with_cycles_types_lists_and_flags(): void
    {
        $this->assertCount(125, FieldCatalogue::FIELDS);

        $response = $this->actingAsStaff($this->staffUser())->getJson(self::API.'/field-definitions')->assertOk()->assertJsonCount(125, 'data');
        $fields = collect($response->json('data'))->keyBy('key');

        $this->assertSame('personal', 'personal'); // sections are Arabic labels:
        $this->assertSame('البيانات الشخصية', $fields['national_number']['section']);
        $this->assertSame('once', $fields['national_number']['cycle']['value']);
        $this->assertTrue($fields['national_number']['identity']);
        $this->assertSame('blood_type', $fields['blood_type_id']['list']);
        $this->assertSame('semi_annual', $fields['contract_ends_on']['cycle']['value']);
        $this->assertSame(67, $fields->where('counts_for_completeness', true)->count());
        $this->assertSame(['personal_number', 'first_name', 'father_name', 'family_name', 'mother_name', 'national_number'], $fields->where('identity', true)->keys()->all());
        $this->assertFalse($fields['birth_date']['identity']);
    }

    public function test_completeness_and_missing_fields_by_cycle_follow_the_definitions(): void
    {
        $employee = $this->employeeFile();
        $this->assertSame(round(4 * 100 / 67, 2), (float) $employee->completeness);
        $this->assertContains('phone', $employee->missing_fields['quarterly']);

        $this->actingAsStaff($this->staffUser())->putJson(self::API.'/field-definitions/phone', ['counts_for_completeness' => false])->assertForbidden();

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->putJson(self::API.'/field-definitions/phone', ['counts_for_completeness' => false])->assertOk()
            ->assertJsonPath('data.counts_for_completeness', false);

        $employee->refresh();
        $this->assertSame(round(4 * 100 / 66, 2), (float) $employee->completeness);
        $this->assertNotContains('phone', $employee->missing_fields['quarterly']);

        $this->putJson(self::API.'/field-definitions/residence_city', ['cycle' => 'quarterly'])->assertOk();
        $this->assertContains('residence_city', $employee->refresh()->missing_fields['quarterly']);
        $this->assertDatabaseHas('audit_events', ['event' => 'employees.field_definition_updated']);
        $this->putJson(self::API.'/field-definitions/residence_city', [])->assertUnprocessable();
    }

    public function test_the_workbooks_formulas_are_computed_for_today_in_damascus(): void
    {
        Carbon::setTestNow('2026-11-23 09:00:00');
        $employee = $this->employeeFile([
            'birth_date' => '1990-11-24',
            'appointment_date' => '2016-05-23',
            'contract_signed_on' => '2018-01-01',
            'contract_starts_on' => '2026-01-01',
            'contract_ends_on' => '2026-12-13',
        ]);

        $this->actingAsStaff($this->staffUser())->getJson(self::API."/employees/{$employee->id}")->assertOk()
            ->assertJsonPath('data.computed.full_name', 'أحمد محمد الخطيب')
            ->assertJsonPath('data.computed.age', 35)
            ->assertJsonPath('data.computed.service_years', 10.5)
            ->assertJsonPath('data.computed.contract_days_left', 20)
            ->assertJsonFragment(['code' => 'contract_ending']);
    }

    public function test_the_workbooks_warnings(): void
    {
        $employee = $this->employeeFile([
            'employee_status_id' => $this->itemId('employee_status', 'active'),
            'job_status_id' => $this->itemId('job_status', 'discharged'),
            'contract_ends_on' => '2020-01-01',
        ]);

        $codes = collect($this->actingAsStaff($this->staffUser())->getJson(self::API."/employees/{$employee->id}")->json('data.warnings'))->pluck('code');

        $this->assertContains('discharge_date_missing', $codes);
        $this->assertContains('active_but_left', $codes);
        $this->assertContains('contract_ended', $codes);
    }

    public function test_the_list_searches_filters_and_sorts(): void
    {
        $root = DB::table('org_units')->insertGetId(['level' => 1, 'name' => 'القيادة', 'search_key' => 'القياده', 'created_at' => now(), 'updated_at' => now()]);
        $child = DB::table('org_units')->insertGetId(['parent_id' => $root, 'level' => 2, 'name' => 'مديرية', 'search_key' => 'مديريه', 'created_at' => now(), 'updated_at' => now()]);

        $this->employeeFile(['first_name' => 'فاطمة', 'national_number' => '01234500000', 'org_unit_id' => $child, 'contract_ends_on' => now()->addDays(10)->format('Y-m-d')]);
        $this->employeeFile(['first_name' => 'مصطفى', 'personal_number' => '7788', 'org_unit_id' => $root]);
        $other = $this->employeeFile(['first_name' => 'يوسف']);

        $this->actingAsStaff($this->staffUser());
        $this->getJson(self::API.'/employees?q=فاطمه')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'فاطمة محمد الخطيب')
            ->assertJsonPath('data.0.org_unit.path_label', 'القيادة / مديرية');
        $this->getJson(self::API.'/employees?q=مصطفي')->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/employees?q=٠١٢٣٤')->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/employees?q=7788')->assertJsonCount(1, 'data');
        $this->getJson(self::API."/employees?org_unit_id={$root}")->assertJsonCount(2, 'data');
        $this->getJson(self::API."/employees?org_unit_id={$root}&include_sub_units=0")->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/employees?contract_ends_within_days=30')->assertJsonCount(1, 'data')->assertJsonPath('data.0.contract_days_left', 10);
        $this->getJson(self::API.'/employees?completeness_below=100')->assertJsonCount(3, 'data');

        $this->patchJson(self::API."/employees/{$other->id}", ['values' => ['notes' => 'x']])->assertCreated();
        $this->getJson(self::API.'/employees?has_pending=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.pending_changes', 1);
        $this->getJson(self::API.'/employees?sort=name')->assertJsonPath('data.0.full_name', 'فاطمة محمد الخطيب');
        $this->getJson(self::API.'/employees?sort=bad')->assertUnprocessable();
    }

    public function test_a_unit_with_people_is_not_archived(): void
    {
        $unit = DB::table('org_units')->insertGetId(['level' => 1, 'name' => 'القيادة', 'search_key' => 'القياده', 'created_at' => now(), 'updated_at' => now()]);
        $this->employeeFile(['org_unit_id' => $unit]);

        $this->actingAsStaff($this->admin())->postJson(self::API."/settings/org-units/{$unit}/archive")->assertUnprocessable()
            ->assertJsonPath('errors.unit.0', __('settings.org_has_employees', ['count' => 1]));
    }

    public function test_the_definitions_flags_survive_reseeding(): void
    {
        FieldDefinition::query()->whereKey('phone')->update(['counts_for_completeness' => false, 'cycle' => 'annual']);
        $this->seed(FieldDefinitionsSeeder::class);

        $phone = FieldDefinition::query()->findOrFail('phone');
        $this->assertFalse($phone->counts_for_completeness);
        $this->assertSame('annual', $phone->cycle->value);
    }
}
