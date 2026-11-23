<?php

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Models\LookupItem;
use App\Modules\Settings\Models\LookupList;
use App\Modules\Settings\Support\ListCatalogue;
use App\Support\ArabicText;
use Illuminate\Database\Seeder;

/**
 * Every environment: the lists of the catalogue (D-024). A list gets the workbook's items only when it is
 * first created; afterwards its items are the settings managers' data. System items (codes) are ensured on
 * every run: an existing item with the same word takes the code, otherwise it is added (D-025). Re-run safe.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ListCatalogue::LISTS as $key => [$label, $usedBy, $items]) {
            $list = LookupList::query()->firstOrNew(['key' => $key]);
            $isNew = ! $list->exists;
            $list->fill(['label_ar' => $label, 'description_ar' => $usedBy])->save();

            if ($isNew) {
                foreach (array_values($items) as $position => $item) {
                    [$itemLabel, $code] = is_array($item) ? $item : [$item, null];
                    $this->create($list, $itemLabel, $code, $position + 1);
                }

                continue;
            }

            foreach (ListCatalogue::systemItems($key) as $code => $itemLabel) {
                if ($list->items()->where('code', $code)->exists()) {
                    continue;
                }

                $same = $list->items()->where('search_key', ArabicText::searchKey($itemLabel))->first();

                if ($same !== null) {
                    $same->forceFill(['code' => $code, 'archived_at' => null, 'archived_by' => null])->save();
                } else {
                    $this->create($list, $itemLabel, $code, (int) $list->items()->max('sort_order') + 1);
                }
            }
        }
    }

    private function create(LookupList $list, string $label, ?string $code, int $sortOrder): void
    {
        $item = new LookupItem(['label' => $label]);
        $item->forceFill(['lookup_list_id' => $list->id, 'code' => $code, 'sort_order' => $sortOrder])->save();
    }
}
