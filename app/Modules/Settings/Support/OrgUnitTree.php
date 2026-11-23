<?php

namespace App\Modules\Settings\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The whole structure in one recursive query, each unit with its path of names from the top, ordered so
 * that a parent comes before its children (D-026).
 */
final class OrgUnitTree
{
    /**
     * @return Collection<int, object{id: int, parent_id: ?int, level: int, name: string, archived_at: ?string, path: list<string>, path_ids: list<int>}>
     */
    public static function all(bool $includeArchived = false): Collection
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE tree AS (
                SELECT id, parent_id, level, name, archived_at,
                       ARRAY[name]::text[] AS path, ARRAY[id]::bigint[] AS path_ids
                FROM org_units
                WHERE parent_id IS NULL
                UNION ALL
                SELECT u.id, u.parent_id, u.level, u.name, u.archived_at,
                       t.path || u.name::text, t.path_ids || u.id
                FROM org_units u
                JOIN tree t ON u.parent_id = t.id
            )
            SELECT id, parent_id, level, name, archived_at, array_to_json(path) AS path_json, array_to_json(path_ids) AS path_ids_json
            FROM tree
            WHERE (? OR archived_at IS NULL)
            ORDER BY tree.path, tree.id
            SQL, [$includeArchived]);

        return collect($rows)->map(function (object $row) {
            $row->path = json_decode($row->path_json, true);
            $row->path_ids = json_decode($row->path_ids_json, true);
            unset($row->path_json, $row->path_ids_json);

            return $row;
        });
    }
}
