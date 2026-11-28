<?php

namespace App\Modules\Reports\Support;

use App\Modules\Settings\Support\ListCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * A list's items as a report reads them (D-051): a system item by its code, so renaming it changes nothing, and
 * every item in the list's order for a breakdown, so an item the settings managers add gets its own tile.
 */
final class ListItems
{
    /** The id of a system item (D-025), or null. */
    public static function id(string $list, string $code): ?int
    {
        $id = DB::table(ListCatalogue::table($list))->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{id: int, name_ar: string, archived: bool}|null an item as the hiring system gives it */
    public static function ref(string $list, ?int $id): ?array
    {
        $row = $id === null ? null : DB::table(ListCatalogue::table($list))->where('id', $id)->first(['id', 'label', 'archived_at']);

        return $row === null ? null : ['id' => (int) $row->id, 'name_ar' => $row->label, 'archived' => $row->archived_at !== null];
    }

    /** @return list<int> */
    public static function ids(string $list, array $codes): array
    {
        return DB::table(ListCatalogue::table($list))->whereIn('code', $codes)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The items with their counts: the active ones always, an archived one only when it is counted.
     *
     * @param  array<int, int>  $counts  item id => count
     * @return list<array{item: array{id: int, name_ar: string, archived: bool}, code: ?string, count: int}>
     */
    public static function breakdown(string $list, array $counts): array
    {
        $rows = DB::table(ListCatalogue::table($list))->orderBy('sort_order')->orderBy('id')->get(['id', 'label', 'code', 'archived_at']);
        $out = [];

        foreach ($rows as $row) {
            $count = $counts[(int) $row->id] ?? 0;

            if ($row->archived_at !== null && $count === 0) {
                continue;
            }

            $out[] = [
                'item' => ['id' => (int) $row->id, 'name_ar' => $row->label, 'archived' => $row->archived_at !== null],
                'code' => $row->code,
                'count' => $count,
            ];
        }

        return $out;
    }
}
