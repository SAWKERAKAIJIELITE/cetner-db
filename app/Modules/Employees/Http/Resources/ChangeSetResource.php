<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Models\EmployeeChangeSet;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\ReviewRights;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * A submission with each field's approved value beside the proposed one.
 *
 * @mixin EmployeeChangeSet
 */
class ChangeSetResource extends JsonResource
{
    public static ?ValueLabels $labels = null;

    public function toArray(Request $request): array
    {
        $labels = self::$labels ??= new ValueLabels;
        $user = $request->user();

        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee_id,
                'full_name' => $this->employee?->fullName() ?? $this->proposedName(),
                'file_status' => $this->employee?->file_status->value,
            ],
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'cycle' => $this->cycle?->value,
            'cycle_label' => $this->cycle?->label(),
            'period' => $this->period,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'needs_identity_review' => $this->needs_identity_review,
            'note' => $this->note,
            'submitted_by' => new StaffUserSummaryResource($this->submitter),
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'self_approved' => $this->self_approved,
            'reviewed_by' => $this->reviewer ? new StaffUserSummaryResource($this->reviewer) : null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            /** Whether the signed-in user may approve or refuse it now. */
            'can_decide' => $this->isPending() && $user->hasPermission('entries.review') && $this->allowed(fn () => ReviewRights::ensureCanDecide($user, $this->resource)),
            'items' => $this->items->map(fn ($item) => [
                'field' => $item->field,
                'label' => FieldCatalogue::get($item->field)['label'],
                'identity' => FieldCatalogue::get($item->field)['identity'],
                'old_value' => $item->old_value,
                'old_display' => $labels->display($item->field, $item->old_value),
                'new_value' => $item->new_value,
                'new_display' => $labels->display($item->field, $item->new_value),
            ])->values(),
        ];
    }

    private function proposedName(): ?string
    {
        $parts = $this->items->whereIn('field', ['first_name', 'father_name', 'family_name'])->sortBy(fn ($i) => array_search($i->field, ['first_name', 'father_name', 'family_name'], true));
        $name = trim($parts->pluck('new_value')->implode(' '));

        return $name === '' ? null : $name;
    }

    private function allowed(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
