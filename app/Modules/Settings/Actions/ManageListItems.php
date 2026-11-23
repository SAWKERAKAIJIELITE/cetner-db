<?php

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Models\LookupItem;
use App\Modules\Settings\Models\LookupList;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Items of a managed list (D-024, D-025): added, renamed, reordered, archived and restored; never deleted.
 * The same word typed another way is a duplicate (search key). A system item is renamed but never archived.
 */
final class ManageListItems
{
    public function add(LookupList $list, string $label, ?int $sortOrder, StaffUser $actor): LookupItem
    {
        return DB::transaction(function () use ($list, $label, $sortOrder, $actor) {
            $this->lockList($list);
            $this->refuseDuplicate($list, $label);

            $item = new LookupItem(['label' => $label]);
            $item->forceFill([
                'lookup_list_id' => $list->getKey(),
                'sort_order' => $sortOrder ?? ((int) $list->items()->max('sort_order') + 1),
                'created_by' => $actor->getKey(),
            ])->save();

            AuditTrail::record('settings.list_item_added', $actor, [
                'list' => $list->key,
                'lookup_item_id' => $item->getKey(),
                'label' => $item->label,
            ]);

            return $item;
        });
    }

    public function update(LookupItem $item, ?string $label, ?int $sortOrder, StaffUser $actor): LookupItem
    {
        return DB::transaction(function () use ($item, $label, $sortOrder, $actor) {
            $list = $item->list;
            $this->lockList($list);
            $item = LookupItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $before = ['label' => $item->label, 'sort_order' => $item->sort_order];

            if ($label !== null) {
                $this->refuseDuplicate($list, $label, $item);
                $item->label = $label;
            }

            if ($sortOrder !== null) {
                $item->forceFill(['sort_order' => $sortOrder]);
            }

            if ($item->isDirty()) {
                $item->save();

                AuditTrail::record('settings.list_item_updated', $actor, [
                    'list' => $list->key,
                    'lookup_item_id' => $item->getKey(),
                    'from' => $before,
                    'to' => ['label' => $item->label, 'sort_order' => $item->sort_order],
                ]);
            }

            return $item;
        });
    }

    public function archive(LookupItem $item, StaffUser $actor): LookupItem
    {
        return DB::transaction(function () use ($item, $actor) {
            $item = LookupItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if ($item->isSystem()) {
                throw ValidationException::withMessages(['item' => __('settings.system_item')]);
            }

            if ($item->isArchived()) {
                throw ValidationException::withMessages(['item' => __('settings.already_archived')]);
            }

            $item->forceFill(['archived_at' => now(), 'archived_by' => $actor->getKey()])->save();

            AuditTrail::record('settings.list_item_archived', $actor, [
                'list' => $item->list->key,
                'lookup_item_id' => $item->getKey(),
                'label' => $item->label,
            ]);

            return $item;
        });
    }

    public function restore(LookupItem $item, StaffUser $actor): LookupItem
    {
        return DB::transaction(function () use ($item, $actor) {
            $item = LookupItem::query()->lockForUpdate()->findOrFail($item->getKey());

            if (! $item->isArchived()) {
                throw ValidationException::withMessages(['item' => __('settings.not_archived')]);
            }

            $item->forceFill(['archived_at' => null, 'archived_by' => null])->save();

            AuditTrail::record('settings.list_item_restored', $actor, [
                'list' => $item->list->key,
                'lookup_item_id' => $item->getKey(),
                'label' => $item->label,
            ]);

            return $item;
        });
    }

    /**
     * The order the forms show: the ids of every active item, in order. Archived items follow.
     *
     * @param  list<int>  $ids
     */
    public function reorder(LookupList $list, array $ids, StaffUser $actor): void
    {
        DB::transaction(function () use ($list, $ids, $actor) {
            $this->lockList($list);
            $active = LookupItem::query()->where('lookup_list_id', $list->getKey())->active()->pluck('id')->all();

            sort($active);
            $given = $ids;
            sort($given);

            if ($active !== $given) {
                throw ValidationException::withMessages(['item_ids' => __('settings.reorder_all_items')]);
            }

            foreach (array_values($ids) as $position => $id) {
                LookupItem::query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }

            $last = count($ids);
            LookupItem::query()->where('lookup_list_id', $list->getKey())->whereNotNull('archived_at')
                ->orderBy('sort_order')->orderBy('id')->pluck('id')
                ->each(function (int $id) use (&$last) {
                    LookupItem::query()->whereKey($id)->update(['sort_order' => ++$last]);
                });

            AuditTrail::record('settings.list_reordered', $actor, ['list' => $list->key, 'lookup_item_ids' => array_values($ids)]);
        });
    }

    /** Serialises the changes of one list, so two people cannot add the same word at once. */
    private function lockList(LookupList $list): void
    {
        LookupList::query()->whereKey($list->getKey())->lockForUpdate()->first();
    }

    private function refuseDuplicate(LookupList $list, string $label, ?LookupItem $except = null): void
    {
        $existing = LookupItem::query()
            ->where('lookup_list_id', $list->getKey())
            ->where('search_key', ArabicText::searchKey(trim(preg_replace('/\s+/u', ' ', $label) ?? $label)))
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['label' => $existing->isArchived()
                ? __('settings.item_duplicate_archived', ['label' => $existing->label])
                : __('settings.item_duplicate', ['label' => $existing->label])]);
        }
    }
}
