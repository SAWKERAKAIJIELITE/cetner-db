<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Settings\Models\OrgUnit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrgUnitTest extends TestCase
{
    private function add(string $name, ?int $parentId = null): int
    {
        return $this->postJson(self::API.'/settings/org-units', ['name' => $name, 'parent_id' => $parentId])->assertCreated()->json('data.id');
    }

    public function test_a_tree_of_six_levels_is_built_with_paths(): void
    {
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));

        $ids = [];
        $parent = null;

        foreach (['القيادة العامة', 'مديرية الإدارة', 'فرع الموارد البشرية', 'شعبة التجنيد', 'قسم الاختبارات', 'مكتب القبول'] as $name) {
            $parent = $ids[] = $this->add($name, $parent);
        }

        $this->getJson(self::API.'/settings/org-units')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonPath('data.5.level', 6)
            ->assertJsonPath('data.5.path_label', 'القيادة العامة / مديرية الإدارة / فرع الموارد البشرية / شعبة التجنيد / قسم الاختبارات / مكتب القبول')
            ->assertJsonPath('data.5.path_ids', $ids);

        $this->postJson(self::API.'/settings/org-units', ['name' => 'سابع', 'parent_id' => $parent])->assertUnprocessable()
            ->assertJsonPath('errors.parent_id.0', __('settings.org_max_depth', ['max' => 6]));

        $this->assertDatabaseHas('audit_events', ['event' => 'settings.org_unit_added']);
    }

    public function test_siblings_have_distinct_names_and_cousins_may_share_one(): void
    {
        $this->actingAsStaff($this->admin());
        $root = $this->add('القيادة العامة');
        $a = $this->add('مديرية الإدارة', $root);
        $b = $this->add('مديرية العمليات', $root);

        $this->add('فرع المالية', $a);
        $this->add('فرع المالية', $b);

        $this->postJson(self::API.'/settings/org-units', ['name' => 'مديريه الاداره', 'parent_id' => $root])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->postJson(self::API.'/settings/org-units', ['name' => 'القيادة العامة'])->assertUnprocessable();
    }

    public function test_a_unit_is_archived_after_its_children_and_restored_under_an_active_parent(): void
    {
        $this->actingAsStaff($this->admin());
        $root = $this->add('القيادة العامة');
        $child = $this->add('مديرية الإدارة', $root);

        $this->postJson(self::API."/settings/org-units/{$root}/archive")->assertUnprocessable()
            ->assertJsonPath('errors.unit.0', __('settings.org_has_active_children'));

        $this->postJson(self::API."/settings/org-units/{$child}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $this->postJson(self::API."/settings/org-units/{$root}/archive")->assertOk();
        $this->getJson(self::API.'/settings/org-units')->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/settings/org-units?include_archived=1')->assertJsonCount(2, 'data');

        $this->postJson(self::API."/settings/org-units/{$child}/restore")->assertUnprocessable()
            ->assertJsonPath('errors.unit.0', __('settings.org_parent_archived'));
        $this->postJson(self::API.'/settings/org-units', ['name' => 'جديدة', 'parent_id' => $root])->assertUnprocessable();

        $this->postJson(self::API."/settings/org-units/{$root}/restore")->assertOk();
        $this->postJson(self::API."/settings/org-units/{$child}/restore")->assertOk();
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.org_unit_restored']);
    }

    public function test_a_unit_is_renamed(): void
    {
        $this->actingAsStaff($this->admin());
        $root = $this->add('القيادة العامة');
        $child = $this->add('مديرية الادارة', $root);

        $this->putJson(self::API."/settings/org-units/{$child}", ['name' => 'مديرية الإدارة'])->assertOk()
            ->assertJsonPath('data.path_label', 'القيادة العامة / مديرية الإدارة');
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.org_unit_renamed']);
    }

    public function test_the_database_guards_the_tree(): void
    {
        $this->actingAsStaff($this->admin());
        $root = $this->add('القيادة العامة');
        $child = $this->add('مديرية', $root);

        foreach ([
            fn () => DB::table('org_units')->insert(['parent_id' => $root, 'level' => 3, 'name' => 'خطأ', 'search_key' => 'خطا', 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('org_units')->insert(['parent_id' => null, 'level' => 2, 'name' => 'خطأ', 'search_key' => 'خطا', 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('org_units')->where('id', $root)->update(['archived_at' => now()]),
            fn () => DB::table('org_units')->where('id', $child)->update(['parent_id' => null, 'level' => 1]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('the tree was broken');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(2, OrgUnit::query()->count());
    }

    public function test_data_entry_reads_the_structure_and_does_not_change_it(): void
    {
        $this->actingAsStaff($this->staffUser());

        $this->getJson(self::API.'/settings/org-units')->assertOk();
        $this->postJson(self::API.'/settings/org-units', ['name' => 'القيادة العامة'])->assertForbidden();
    }
}
