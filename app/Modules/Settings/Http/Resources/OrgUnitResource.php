<?php

namespace App\Modules\Settings\Http\Resources;

use App\Modules\Settings\Models\OrgUnit;
use App\Modules\Settings\Support\OrgUnitTree;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A unit with its path from the top: a row of OrgUnitTree, or a model after a change.
 *
 * @mixin OrgUnit
 */
class OrgUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $row = $this->resource instanceof OrgUnit
            ? OrgUnitTree::all(true, withCounts: true)->firstWhere('id', $this->resource->id)
            : $this->resource;

        return [
            'id' => (int) $row->id,
            'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            'level' => (int) $row->level,
            'name_ar' => $row->name,
            /** @var list<string> the names from the top unit down to this one */
            'path' => $row->path,
            /** @var list<int> */
            'path_ids' => $row->path_ids,
            /** The path as one line: «القيادة العامة / مديرية العمليات». */
            'path_label' => implode(' / ', $row->path),
            'archived' => $row->archived_at !== null,
            /** «عدد الموظفين الفعّالين»: active employees of this unit and every unit under it. */
            'active_employees' => (int) $row->active_employees,
            /** Of this unit itself. */
            'direct_active_employees' => (int) $row->direct_active_employees,
        ];
    }
}
