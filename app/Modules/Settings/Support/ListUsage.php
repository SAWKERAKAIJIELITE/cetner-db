<?php

namespace App\Modules\Settings\Support;

use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\RecordCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * «عدد المستخدمين» of «القوائم المنسدلة»: how many approved files and kept records use each item of a list, so a
 * settings manager sees what archiving it touches. Read from the catalogues: every list column of the file and of
 * the records. One grouped query per column.
 */
final class ListUsage
{
    /** @return array<int, int> item id => files and records using it */
    public static function counts(string $list): array
    {
        $counts = [];

        foreach (self::columns($list) as [$table, $column, $kept]) {
            $rows = DB::table($table)->whereNotNull($column)
                ->when($kept === 'file', fn ($q) => $q->where('file_status', 'active'))
                ->when($kept === 'record', fn ($q) => $q->whereNull('deleted_at'))
                ->groupBy($column)->selectRaw("{$column} AS item, count(*) AS n")->get();

            foreach ($rows as $row) {
                $counts[(int) $row->item] = ($counts[(int) $row->item] ?? 0) + (int) $row->n;
            }
        }

        return $counts;
    }

    /** @return list<array{0: string, 1: string, 2: string}> table, column, how its kept rows are found */
    private static function columns(string $list): array
    {
        $columns = [];

        foreach (FieldCatalogue::FIELDS as $key => $field) {
            if (($field['type'] ?? null) === 'list' && ($field['list'] ?? null) === $list) {
                $columns[] = ['employees', $key, 'file'];
            }
        }

        foreach (RecordType::cases() as $type) {
            foreach (RecordCatalogue::fields($type) as $key => $field) {
                if ($field['type'] === 'list' && $field['list'] === $list) {
                    $columns[] = [$type->table(), $key, 'record'];
                }
            }
        }

        return $columns;
    }
}
