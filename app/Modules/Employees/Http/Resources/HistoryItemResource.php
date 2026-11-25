<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Models\EmployeeChangeItem;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One approved change of one field (D-033): from what to what, who sent it, who approved it.
 *
 * @mixin EmployeeChangeItem
 */
class HistoryItemResource extends JsonResource
{
    public static ?ValueLabels $labels = null;

    public function toArray(Request $request): array
    {
        $labels = self::$labels ??= new ValueLabels;
        $set = $this->changeSet;

        return [
            'field' => $this->field,
            'label' => (string) FieldCatalogue::get($this->field)['label'],
            /** @var string|int|float|bool|null the value before, in the field's normal form */
            'old_value' => $this->old_value,
            'old_display' => $labels->display($this->field, $this->old_value),
            /** @var string|int|float|bool|null */
            'new_value' => $this->new_value,
            'new_display' => $labels->display($this->field, $this->new_value),
            'change_set_id' => $this->change_set_id,
            'kind' => $set->kind->value,
            'kind_label' => $set->kind->label(),
            'cycle' => $set->cycle?->value,
            'cycle_label' => $set->cycle?->label(),
            'period' => $set->period,
            'submitted_by' => new StaffUserSummaryResource($set->submitter),
            'submitted_at' => $set->submitted_at->toIso8601String(),
            'approved_by' => new StaffUserSummaryResource($set->reviewer),
            'approved_at' => $set->reviewed_at?->toIso8601String(),
            'self_approved' => $set->self_approved,
        ];
    }
}
