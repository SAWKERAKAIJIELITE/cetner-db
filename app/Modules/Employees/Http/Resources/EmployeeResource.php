<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeComputed;
use App\Modules\Employees\Support\EmployeeWarnings;
use App\Modules\Employees\Support\FieldDefinitions;
use App\Modules\Employees\Support\FieldValues;
use App\Modules\Employees\Support\IdentityLock;
use App\Modules\Employees\Support\ReviewStatus;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The file: every field's approved value with the changes waiting beside it (D-033), the workbook's formulas
 * (D-035), completeness and missing fields, warnings (D-037) and the four cycles (D-036).
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $labels = new ValueLabels;
        $sets = $this->changeSets()->where('status', ChangeSetStatus::Pending)->with(['items', 'submitter'])->orderBy('submitted_at')->get();
        $pending = [];

        foreach ($sets as $set) {
            foreach ($set->items as $item) {
                $labels->add($item->field, $item->new_value);
                $pending[$item->field][] = [
                    'change_set_id' => $set->id,
                    'value' => $item->new_value,
                    'display' => null,
                    'submitted_by' => new StaffUserSummaryResource($set->submitter),
                    'submitted_at' => $set->submitted_at->toIso8601String(),
                    'needs_identity_review' => $set->needs_identity_review,
                ];
            }
        }

        $fields = [];

        foreach (FieldDefinitions::all() as $key => $definition) {
            $value = FieldValues::current($this->resource, $key);
            $labels->add($key, $value);
            $fields[$key] = [$definition, $value];
        }

        $reviews = $this->isActive() ? ReviewStatus::of($this->id) : [];

        return [
            'id' => $this->id,
            'file_status' => ['value' => $this->file_status->value, 'label' => $this->file_status->label()],
            'full_name' => $this->fullName(),
            'created_at' => $this->created_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            /** The identity lock (D-034): once locked, the six identity fields are never changed. */
            'identity' => [
                'locked' => IdentityLock::isLocked($this->resource),
                'locked_at' => $this->identity_locked_at?->toIso8601String(),
                'locked_by' => $this->identityLocker ? new StaffUserSummaryResource($this->identityLocker) : null,
            ],
            'fields' => collect($fields)->map(fn ($pair, $key) => [
                'label' => $pair[0]->label_ar,
                'section' => $pair[0]->section_ar,
                'cycle' => $pair[0]->cycle->value,
                'type' => $pair[0]->type,
                'identity' => $pair[0]->identity,
                /** A locked identity value: refused for everyone. */
                'locked' => $pair[0]->identity && IdentityLock::isLocked($this->resource) && $pair[1] !== null,
                'value' => $pair[1],
                'display' => $labels->display($key, $pair[1]),
                'pending' => array_map(fn ($p) => ['display' => $labels->display($key, $p['value'])] + $p, $pending[$key] ?? []),
            ]),
            /** The workbook's formulas, as of today in Damascus. */
            'computed' => [
                'full_name' => $this->fullName(),
                'age' => EmployeeComputed::age($this->resource),
                'service_years' => EmployeeComputed::serviceYears($this->resource),
                'contract_days_left' => EmployeeComputed::contractDaysLeft($this->resource),
                'org_unit_path' => $labels->unitPath($this->org_unit_id),
                'current_work_unit_path' => $labels->unitPath($this->current_work_unit_id ?? $this->org_unit_id),
            ],
            'completeness' => (float) $this->completeness,
            /** Counted fields still empty, by cycle. */
            'missing_fields' => collect($this->missing_fields ?? [])->map(fn ($keys, $cycle) => [
                'cycle' => ['value' => $cycle, 'label' => Cycle::from($cycle)->label()],
                'count' => count($keys),
                'fields' => array_map(fn ($k) => ['key' => $k, 'label' => FieldDefinitions::all()[$k]->label_ar], $keys),
            ])->values(),
            'warnings' => $this->isActive() ? EmployeeWarnings::of($this->resource, $reviews) : [],
            'reviews' => $reviews,
            'pending_change_sets' => $sets->map(fn ($set) => [
                'id' => $set->id,
                'kind' => $set->kind->value,
                'cycle' => $set->cycle?->value,
                'period' => $set->period,
                'needs_identity_review' => $set->needs_identity_review,
                'fields_count' => $set->items->count(),
                'submitted_by' => new StaffUserSummaryResource($set->submitter),
                'submitted_at' => $set->submitted_at->toIso8601String(),
            ])->values(),
        ];
    }
}
