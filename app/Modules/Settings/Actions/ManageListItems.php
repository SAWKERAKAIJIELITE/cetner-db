<?php

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Models\ListItem;
use App\Modules\Settings\Support\ManagedList;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Items of a managed list (D-024, D-025): added, renamed, reordered, archived and restored; never deleted.
 * The same word typed another way is a duplicate (search key). A system item is renamed but never archived.
 */
final class ManageListItems
{
    public function add(ManagedList $list, string $label, ?int $sortOrder, StaffUser $actor): ListItem
    {
        return DB::transaction(function () use ($list, $label, $sortOrder, $actor) {
            $this->lockList($list);
            $this->refuseDuplicate($list, $label);

            $item = ListItem::forList($list->key)->fill(['label' => $label]);
            $item->forceFill([
                'sort_order' => $sortOrder ?? ((int) $list->query()->max('sort_order') + 1),
                'created_by' => $actor->getKey(),
            ])->save();

            AuditTrail::record('settings.list_item_added', $actor, [
                'list' => $list->key,
                'list_item_id' => $item->getKey(),
                'label' => $item->label,
            ]);

            return $item;
        });
    }

    public function update(ManagedList $list, int $itemId, ?string $label, ?int $sortOrder, StaffUser $actor): ListItem
    {
        return DB::transaction(function () use ($list, $itemId, $label, $sortOrder, $actor) {
            $this->lockList($list);
            $item = $list->query()->lockForUpdate()->findOrFail($itemId);
            $before = ['label' => $item->label, 'sort_order' => $item->sort_order];

            if ($label !== null) {
                $this->refuseDuplicate($list, $label, $item);
                $item->label = ListItem::clean($label);
            }

            if ($sortOrder !== null) {
                $item->forceFill(['sort_order' => $sortOrder]);
            }

            if ($item->isDirty()) {
                $item->save();

                AuditTrail::record('settings.list_item_updated', $actor, [
                    'list' => $list->key,
                    'list_item_id' => $item->getKey(),
                    'from' => $before,
                    'to' => ['label' => $item->label, 'sort_order' => $item->sort_order],
                ]);
            }

            return $item;
        });
    }

    public function archive(ManagedList $list, int $itemId, StaffUser $actor): ListItem
    {
        return DB::transaction(function () use ($list, $itemId, $actor) {
            $item = $list->query()->lockForUpdate()->findOrFail($itemId);

            if ($item->isSystem()) {
                throw ValidationException::withMessages(['item' => __('settings.system_item')]);
            }

            if ($item->isArchived()) {
                throw ValidationException::withMessages(['item' => __('settings.already_archived')]);
            }

            $item->forceFill(['archived_at' => now(), 'archived_by' => $actor->getKey()])->save();

            AuditTrail::record('settings.list_item_archived', $actor, [
                'list' => $list->key,
                'list_item_id' => $item->getKey(),
                'label' => $item->label,
            ]);

            return $item;
        });
    }

    public function restore(ManagedList $list, int $itemId, StaffUser $actor): ListItem
    {
        return DB::transaction(function () use ($list, $itemId, $actor) {
            $item = $list->query()->lockForUpdate()->findOrFail($itemId);

            if (! $item->isArchived()) {
                throw ValidationException::withMessages(['item' => __('settings.not_archived')]);
            }

            $item->forceFill(['archived_at' => null, 'archived_by' => null])->save();

            AuditTrail::record('settings.list_item_restored', $actor, [
                'list' => $list->key,
                'list_item_id' => $item->getKey(),
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
    public function reorder(ManagedList $list, array $ids, StaffUser $actor): void
    {
        DB::transaction(function () use ($list, $ids, $actor) {
            $this->lockList($list);
            $active = $list->query()->whereNull('archived_at')->pluck('id')->all();

            sort($active);
            $given = $ids;
            sort($given);

            if ($active !== $given) {
                throw ValidationException::withMessages(['item_ids' => __('settings.reorder_all_items')]);
            }

            foreach (array_values($ids) as $position => $id) {
                $list->query()->whereKey($id)->update(['sort_order' => $position + 1]);
            }

            $last = count($ids);
            $list->query()->whereNotNull('archived_at')->orderBy('sort_order')->orderBy('id')->pluck('id')
                ->each(function (int $id) use (&$last, $list) {
                    $list->query()->whereKey($id)->update(['sort_order' => ++$last]);
                });

            AuditTrail::record('settings.list_reordered', $actor, ['list' => $list->key, 'list_item_ids' => array_values($ids)]);
        });
    }

    /** Serialises the changes of one list, so two people cannot add the same word at once. */
    private function lockList(ManagedList $list): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['managed_list.'.$list->key]);
    }

    private function refuseDuplicate(ManagedList $list, string $label, ?ListItem $except = null): void
    {
        $existing = $list->query()
            ->where('search_key', ArabicText::searchKey(ListItem::clean($label)))
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['label' => $existing->isArchived()
                ? __('settings.item_duplicate_archived', ['label' => $existing->label])
                : __('settings.item_duplicate', ['label' => $existing->label])]);
        }
    }
}
