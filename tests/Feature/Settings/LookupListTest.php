<?php

namespace Tests\Feature\Settings;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Settings\Database\Seeders\SettingsSeeder;
use App\Modules\Settings\Models\ListItem;
use App\Modules\Settings\Support\ListCatalogue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LookupListTest extends TestCase
{
    private function item(string $list, string $label): ListItem
    {
        return ListItem::queryFor($list)->where('label', $label)->sole();
    }

    public function test_the_workbooks_lists_are_seeded_in_order_with_their_system_items(): void
    {
        $this->assertCount(28, ListCatalogue::LISTS);

        foreach (ListCatalogue::keys() as $key) {
            $this->assertTrue(Schema::hasTable(ListCatalogue::table($key)), "the list {$key} has its own table");
        }

        $this->assertSame(8, DB::table('blood_types')->count());
        $this->assertSame(15, DB::table('movement_types')->count());

        $response = $this->actingAsStaff($this->staffUser())->getJson(self::API.'/settings/lists/job_status')->assertOk()
            ->assertJsonPath('data.label', 'الحالة الوظيفية')
            ->assertJsonPath('data.items.0.label', 'متعاقد دوام')
            ->assertJsonCount(7, 'data.items');

        $this->assertSame('discharged', collect($response->json('data.items'))->firstWhere('label', 'مسرح')['code']);
        $this->getJson(self::API.'/settings/lists/job_title')->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson(self::API.'/settings/lists')->assertOk()->assertJsonCount(28, 'data');
        $this->getJson(self::API.'/settings/lists/no_such_list')->assertNotFound();
    }

    public function test_every_signed_in_user_reads_the_lists_and_only_settings_managers_change_them(): void
    {
        foreach ([StaffRole::DataEntry, StaffRole::GeneralManager] as $role) {
            $this->actingAsStaff($this->staffUser($role));
            $this->getJson(self::API.'/settings/lists')->assertOk();
            $this->postJson(self::API.'/settings/lists/job_title/items', ['label' => 'محاسب'])->assertForbidden();
        }

        foreach ([StaffRole::Supervisor, StaffRole::SystemAdmin] as $role) {
            $this->actingAsStaff($this->staffUser($role));
            $this->postJson(self::API.'/settings/lists/job_title/items', ['label' => 'محاسب '.$role->value])->assertCreated();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/settings/lists')->assertUnauthorized();
    }

    public function test_an_item_is_added_at_the_end_and_audited(): void
    {
        $supervisor = $this->staffUser(StaffRole::Supervisor);

        $this->actingAsStaff($supervisor)->postJson(self::API.'/settings/lists/work_nature/items', ['label' => '  ميداني  '])
            ->assertCreated()->assertJsonPath('data.label', 'ميداني')->assertJsonPath('data.sort_order', 3)->assertJsonPath('data.code', null);

        $this->assertDatabaseHas('audit_events', ['event' => 'settings.list_item_added', 'actor_id' => $supervisor->id, 'category' => 'operations']);
    }

    public function test_the_same_word_typed_another_way_is_a_duplicate(): void
    {
        $this->actingAsStaff($this->admin());

        $this->postJson(self::API.'/settings/lists/housing_type/items', ['label' => 'سكن مع الأهل'])->assertUnprocessable()
            ->assertJsonPath('errors.label.0', __('settings.item_duplicate', ['label' => 'سكن مع الاهل']));

        $this->postJson(self::API.'/settings/lists/blood_type/items', ['label' => 'a+'])->assertUnprocessable();

        // Another list may hold the same word.
        $this->postJson(self::API.'/settings/lists/work_schedule/items', ['label' => 'عمل حر'])->assertCreated();
    }

    public function test_an_item_is_renamed_and_moved(): void
    {
        $this->actingAsStaff($this->admin());
        $item = $this->item('work_nature', 'إداري');

        $this->putJson(self::API."/settings/lists/work_nature/items/{$item->id}", ['label' => 'إداري مكتبي'])->assertOk()
            ->assertJsonPath('data.label', 'إداري مكتبي');
        $this->putJson(self::API."/settings/lists/work_nature/items/{$item->id}", ['sort_order' => 0])->assertOk()
            ->assertJsonPath('data.sort_order', 0);
        $this->putJson(self::API."/settings/lists/work_nature/items/{$item->id}", ['label' => 'توجيهي'])->assertUnprocessable();
        $this->putJson(self::API."/settings/lists/work_nature/items/{$item->id}", [])->assertUnprocessable();

        // An item is reached through its own list only: each list has its own table and its own ids.
        $this->putJson(self::API.'/settings/lists/gender/items/999', ['label' => 'x'])->assertNotFound();
        $this->putJson(self::API."/settings/lists/no_such_list/items/{$item->id}", ['label' => 'x'])->assertNotFound();

        $this->assertDatabaseHas('audit_events', ['event' => 'settings.list_item_updated']);
    }

    public function test_an_archived_item_is_not_offered_and_comes_back_when_restored(): void
    {
        $this->actingAsStaff($this->admin());
        $item = $this->item('blood_type', 'AB-');

        $this->postJson(self::API."/settings/lists/blood_type/items/{$item->id}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $this->getJson(self::API.'/settings/lists/blood_type')->assertJsonCount(7, 'data.items');
        $this->getJson(self::API.'/settings/lists/blood_type?include_archived=1')->assertJsonCount(8, 'data.items');

        $this->postJson(self::API.'/settings/lists/blood_type/items', ['label' => 'AB-'])->assertUnprocessable()
            ->assertJsonPath('errors.label.0', __('settings.item_duplicate_archived', ['label' => 'AB-']));
        $this->postJson(self::API."/settings/lists/blood_type/items/{$item->id}/archive")->assertUnprocessable();

        $this->postJson(self::API."/settings/lists/blood_type/items/{$item->id}/restore")->assertOk()->assertJsonPath('data.archived', false);
        $this->postJson(self::API."/settings/lists/blood_type/items/{$item->id}/restore")->assertUnprocessable();

        $this->assertDatabaseHas('audit_events', ['event' => 'settings.list_item_archived']);
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.list_item_restored']);
    }

    public function test_a_system_item_is_renamed_but_never_archived(): void
    {
        $this->actingAsStaff($this->admin());
        $item = $this->item('job_status', 'مسرح');

        $this->postJson(self::API."/settings/lists/job_status/items/{$item->id}/archive")->assertUnprocessable()
            ->assertJsonPath('errors.item.0', __('settings.system_item'));
        $this->putJson(self::API."/settings/lists/job_status/items/{$item->id}", ['label' => 'مُسرَّح'])->assertOk()
            ->assertJsonPath('data.code', 'discharged');

        $this->expectException(QueryException::class);
        DB::table('job_statuses')->where('id', $item->id)->update(['archived_at' => now()]);
    }

    public function test_each_list_keeps_its_own_items_and_the_same_word_in_two_lists_is_two_rows(): void
    {
        $this->actingAsStaff($this->admin());

        // «عمل حر» is an item of two of the workbook's lists.
        $this->assertSame(1, DB::table('external_entity_types')->where('label', 'عمل حر')->count());
        $this->assertSame(1, DB::table('external_link_types')->where('label', 'عمل حر')->count());

        $id = $this->postJson(self::API.'/settings/lists/job_title/items', ['label' => 'محاسب'])->assertCreated()->json('data.id');
        $this->assertSame('محاسب', DB::table('job_titles')->where('id', $id)->value('label'));
        $this->assertSame('job_title', ListItem::queryFor('job_title')->findOrFail($id)->listKey());
    }

    public function test_the_active_items_are_reordered_together(): void
    {
        $this->actingAsStaff($this->admin());
        $ids = ListItem::queryFor('marital_status')->orderBy('sort_order')->pluck('id')->all();

        $this->putJson(self::API.'/settings/lists/marital_status/order', ['item_ids' => array_reverse($ids)])->assertOk()
            ->assertJsonPath('data.items.0.label', 'أرمل');

        $this->putJson(self::API.'/settings/lists/marital_status/order', ['item_ids' => [$ids[0]]])->assertUnprocessable()
            ->assertJsonPath('errors.item_ids.0', __('settings.reorder_all_items'));
        $this->putJson(self::API.'/settings/lists/marital_status/order', ['item_ids' => [...$ids, 999999]])->assertUnprocessable();
        $this->assertDatabaseHas('audit_events', ['event' => 'settings.list_reordered']);
    }

    public function test_the_seeder_keeps_the_managers_changes_and_restores_missing_system_items(): void
    {
        $this->actingAsStaff($this->admin());
        $archived = $this->item('blood_type', 'O-');
        $this->postJson(self::API."/settings/lists/blood_type/items/{$archived->id}/archive")->assertOk();
        ListItem::queryFor('movement_type')->where('code', 'reward')->update(['code' => null]);

        $this->seed(SettingsSeeder::class);

        $this->assertTrue($archived->refresh()->isArchived());
        $this->assertSame('مكافأة', ListItem::queryFor('movement_type')->where('code', 'reward')->sole()->label);
        $this->assertSame(1, ListItem::queryFor('movement_type')->where('label', 'مكافأة')->count());
    }
}
