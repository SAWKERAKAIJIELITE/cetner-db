<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\RecordType;
use App\Modules\Settings\Support\ListCatalogue;
use App\Modules\Settings\Support\OrgUnitTree;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The labels of the list values of many records, one query per list used (D-031): prime with every value first,
 * then read.
 */
final class RecordLabels
{
    /** @var array<string, array<int, true>> */
    private array $wanted = [];

    /** @var array<string, array<int, array{label: string, code: ?string}>> */
    private array $items = [];

    /** @var array<string, string> debt id => how it reads */
    private array $debts = [];

    /** @param iterable<array<string, mixed>|null> $valueSets field => value, of records of this kind */
    public function prime(RecordType $type, iterable $valueSets): self
    {
        $lists = array_filter(RecordCatalogue::fields($type), fn ($f) => $f['type'] === 'list');

        foreach ($valueSets as $values) {
            foreach ($lists as $key => $field) {
                if (($values[$key] ?? null) !== null && ! isset($this->items[$field['list']][(int) $values[$key]])) {
                    $this->wanted[$field['list']][(int) $values[$key]] = true;
                }
            }
        }

        foreach ($this->wanted as $list => $ids) {
            foreach (DB::table(ListCatalogue::table($list))->whereIn('id', array_keys($ids))->get(['id', 'label', 'code', 'archived_at']) as $row) {
                $this->items[$list][(int) $row->id] = ['label' => $row->label, 'code' => $row->code, 'archived' => $row->archived_at !== null];
            }
        }

        $this->wanted = [];

        // A payment's debt: its creditor (or type) and amount.
        if ($type === RecordType::Payments) {
            $ids = [];

            foreach ($valueSets as $values) {
                if (($values['debt_id'] ?? null) !== null && ! isset($this->debts[$values['debt_id']])) {
                    $ids[] = $values['debt_id'];
                }
            }

            if ($ids !== []) {
                foreach (DB::table('employee_debts AS d')->leftJoin('debt_types AS t', 't.id', '=', 'd.debt_type_id')
                    ->whereIn('d.id', array_unique($ids))->get(['d.id', 'd.creditor', 't.label', 'd.amount']) as $debt) {
                    $this->debts[$debt->id] = trim(($debt->creditor ?? $debt->label ?? 'دين').' ('.number_format((float) $debt->amount, 2, '.', '').')');
                }
            }
        }

        return $this;
    }

    public function display(RecordType $type, string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $field = RecordCatalogue::field($type, $key);

        return match ($field['type']) {
            'list' => $this->items[$field['list']][(int) $value]['label'] ?? null,
            'unit' => $this->unitPath((int) $value),
            'debt' => $this->debts[$value] ?? null,
            default => (string) $value,
        };
    }

    /**
     * A list value as the hiring system gives it: `{id, name_ar, archived}`; null for another field or an empty
     * value.
     *
     * @return array{id: int, name_ar: ?string, archived: bool}|null
     */
    public function ref(RecordType $type, string $key, mixed $value): ?array
    {
        $field = RecordCatalogue::field($type, $key);

        if ($value === null || ! in_array($field['type'], ['list', 'unit'], true)) {
            return null;
        }

        if ($field['type'] === 'unit') {
            return ['id' => (int) $value, 'name_ar' => $this->unitPath((int) $value), 'archived' => $this->unitArchived((int) $value)];
        }

        $item = $this->items[$field['list']][(int) $value] ?? null;

        return ['id' => (int) $value, 'name_ar' => $item['label'] ?? null, 'archived' => (bool) ($item['archived'] ?? false)];
    }

    /** @var Collection<int, object>|null */
    private ?Collection $units = null;

    private function unitPath(int $id): ?string
    {
        $unit = ($this->units ??= OrgUnitTree::all(true)->keyBy('id'))->get($id);

        return $unit === null ? null : implode(' / ', $unit->path);
    }

    private function unitArchived(int $id): bool
    {
        return ($this->units ??= OrgUnitTree::all(true)->keyBy('id'))->get($id)?->archived_at !== null;
    }

    /** @return array{id: int, label: ?string, code: ?string}|null */
    public function item(RecordType $type, string $key, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $item = $this->items[RecordCatalogue::field($type, $key)['list']][(int) $value] ?? null;

        return ['id' => (int) $value, 'label' => $item['label'] ?? null, 'code' => $item['code'] ?? null];
    }
}
