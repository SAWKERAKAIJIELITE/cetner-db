<?php

namespace App\Modules\Imports\Support;

use App\Modules\Settings\Support\ListCatalogue;
use App\Modules\Settings\Support\OrgUnitTree;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;

/**
 * The lists and the organisational structure as an import matches the workbook's text against them (D-053): an
 * item by its label, a unit by its path from the top, each in any spelling (ArabicText::searchKey).
 */
final class ImportLookups
{
    /** @var array<string, array<string, array{id: int, archived: bool, label: string}>> list => key => item */
    private array $lists = [];

    /** @var array<string, array{id: int, archived: bool, path: string}>|null path key => unit */
    private ?array $units = null;

    /**
     * @return array{0: ?int, 1: ?string} the item's id, or an error
     */
    public function item(string $list, string $text, string $listLabel): array
    {
        $this->lists[$list] ??= DB::table(ListCatalogue::table($list))->get(['id', 'label', 'archived_at'])
            ->mapWithKeys(fn ($r) => [ArabicText::searchKey($r->label) => ['id' => (int) $r->id, 'archived' => $r->archived_at !== null, 'label' => $r->label]])
            ->all();

        $item = $this->lists[$list][ArabicText::searchKey($text)] ?? null;

        return match (true) {
            $item === null => [null, __('imports.not_in_list', ['value' => $text, 'list' => $listLabel])],
            $item['archived'] => [null, __('imports.item_archived', ['value' => $item['label'], 'list' => $listLabel])],
            default => [$item['id'], null],
        };
    }

    /**
     * A unit by its names from the top level down, without gaps.
     *
     * @param  array<int, ?string>  $levels  level => name
     * @return array{0: ?int, 1: ?string} the unit's id, or an error; [null, null] when no level is given
     */
    public function unit(array $levels, string $what): array
    {
        ksort($levels);
        $names = [];
        $gap = false;

        foreach ($levels as $name) {
            if ($name === null) {
                $gap = true;

                continue;
            }

            if ($gap) {
                return [null, __('imports.unit_gap', ['what' => $what])];
            }

            $names[] = $name;
        }

        if ($names === []) {
            return [null, null];
        }

        $this->units ??= OrgUnitTree::all(true)->mapWithKeys(fn ($u) => [
            implode('/', array_map(fn ($n) => ArabicText::searchKey($n), $u->path)) => ['id' => (int) $u->id, 'archived' => $u->archived_at !== null, 'path' => implode(' / ', $u->path)],
        ])->all();

        $unit = $this->units[implode('/', array_map(fn ($n) => ArabicText::searchKey($n), $names))] ?? null;

        return match (true) {
            $unit === null => [null, __('imports.unit_unknown', ['what' => $what, 'path' => implode(' / ', $names)])],
            $unit['archived'] => [null, __('imports.unit_archived', ['what' => $what, 'path' => $unit['path']])],
            default => [$unit['id'], null],
        };
    }
}
