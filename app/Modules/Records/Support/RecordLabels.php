<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\RecordType;
use App\Modules\Settings\Support\ListCatalogue;
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
            foreach (DB::table(ListCatalogue::table($list))->whereIn('id', array_keys($ids))->get(['id', 'label', 'code']) as $row) {
                $this->items[$list][(int) $row->id] = ['label' => $row->label, 'code' => $row->code];
            }
        }

        $this->wanted = [];

        return $this;
    }

    public function display(RecordType $type, string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $field = RecordCatalogue::field($type, $key);

        return $field['type'] === 'list' ? ($this->items[$field['list']][(int) $value]['label'] ?? null) : (string) $value;
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
