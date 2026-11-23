<?php

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Models\ListItem;
use App\Modules\Settings\Support\ListCatalogue;
use App\Support\ArabicText;
use Illuminate\Database\Seeder;

/**
 * Every environment: the items of the managed lists (D-024). A list gets the workbook's items only while its
 * table is empty (items are never deleted, so that is its first seeding); afterwards its items are the settings
 * managers' data. System items (codes) are ensured on every run: an existing item with the same word takes
 * the code, otherwise it is added (D-025). Re-run safe.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ListCatalogue::LISTS as $key => [, , , $items]) {
            if (! ListItem::queryFor($key)->exists()) {
                foreach (array_values($items) as $position => $item) {
                    [$label, $code] = is_array($item) ? $item : [$item, null];
                    $this->create($key, $label, $code, $position + 1);
                }

                continue;
            }

            foreach (ListCatalogue::systemItems($key) as $code => $label) {
                if (ListItem::queryFor($key)->where('code', $code)->exists()) {
                    continue;
                }

                $same = ListItem::queryFor($key)->where('search_key', ArabicText::searchKey($label))->first();

                if ($same !== null) {
                    $same->forceFill(['code' => $code, 'archived_at' => null, 'archived_by' => null])->save();
                } else {
                    $this->create($key, $label, $code, (int) ListItem::queryFor($key)->max('sort_order') + 1);
                }
            }
        }
    }

    private function create(string $key, string $label, ?string $code, int $sortOrder): void
    {
        ListItem::forList($key)->fill(['label' => $label])->forceFill(['code' => $code, 'sort_order' => $sortOrder])->save();
    }
}
