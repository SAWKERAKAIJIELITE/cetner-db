<?php

namespace App\Modules\Employees\Support;

use App\Modules\Settings\Support\ListCatalogue;
use App\Modules\Settings\Support\OrgUnitTree;
use Illuminate\Support\Facades\DB;

/**
 * How a value reads: a list item's label, a unit's path, نعم / لا. Collects the ids first, then one query per
 * list used, so a page of files costs a fixed number of queries (D-031).
 */
final class ValueLabels
{
    /** @var array<string, array<int, true>> list key => ids */
    private array $wanted = [];

    /** @var array<string, array<int, array{label: string, code: ?string}>> */
    private array $items = [];

    /** @var array<int, string>|null */
    private ?array $units = null;

    private bool $resolved = false;

    public function add(string $field, mixed $value): void
    {
        if ($value === null || ! FieldCatalogue::has($field)) {
            return;
        }

        $f = FieldCatalogue::get($field);

        if ($f['type'] === 'list') {
            $this->wanted[$f['list']][(int) $value] = true;
            $this->resolved = false;
        }
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        foreach ($this->wanted as $list => $ids) {
            $missing = array_diff(array_keys($ids), array_keys($this->items[$list] ?? []));

            if ($missing === []) {
                continue;
            }

            foreach (DB::table(ListCatalogue::table($list))->whereIn('id', $missing)->get(['id', 'label', 'code', 'archived_at']) as $row) {
                $this->items[$list][(int) $row->id] = ['label' => $row->label, 'code' => $row->code, 'archived' => $row->archived_at !== null];
            }
        }

        $this->resolved = true;
    }

    /** The value as people read it; null for an empty value. */
    public function display(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $f = FieldCatalogue::get($field);

        return match ($f['type']) {
            'list' => $this->item($field, $value)['label'] ?? null,
            'org_unit' => $this->unitPath((int) $value),
            'bool' => $value ? 'نعم' : 'لا',
            default => (string) $value,
        };
    }

    /** @return array{label: string, code: ?string, archived: bool}|null */
    public function item(string $field, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $this->add($field, $value);
        $this->resolve();

        return $this->items[FieldCatalogue::get($field)['list']][(int) $value] ?? null;
    }

    /**
     * A list or unit value as the hiring system gives it: `{id, name_ar, archived}`; null for another field or an
     * empty value. A unit's name_ar is its path.
     *
     * @return array{id: int, name_ar: ?string, archived: bool}|null
     */
    public function ref(string $field, mixed $value): ?array
    {
        if ($value === null || ! FieldCatalogue::has($field)) {
            return null;
        }

        return match (FieldCatalogue::get($field)['type']) {
            'list' => ['id' => (int) $value, 'name_ar' => $this->item($field, $value)['label'] ?? null, 'archived' => (bool) ($this->item($field, $value)['archived'] ?? false)],
            'org_unit' => ['id' => (int) $value, 'name_ar' => $this->unitPath((int) $value), 'archived' => $this->unitArchived((int) $value)],
            default => null,
        };
    }

    private function unitArchived(int $id): bool
    {
        $this->archivedUnits ??= OrgUnitTree::all(true)->filter(fn ($u) => $u->archived_at !== null)->mapWithKeys(fn ($u) => [(int) $u->id => true])->all();

        return isset($this->archivedUnits[$id]);
    }

    /** @var array<int, true>|null */
    private ?array $archivedUnits = null;

    public function unitPath(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $this->units ??= OrgUnitTree::all(true)->mapWithKeys(fn ($u) => [(int) $u->id => implode(' / ', $u->path)])->all();

        return $this->units[$id] ?? null;
    }
}
