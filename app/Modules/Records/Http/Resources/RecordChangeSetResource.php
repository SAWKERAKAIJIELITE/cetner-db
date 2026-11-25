<?php

namespace App\Modules\Records\Http\Resources;

use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use App\Modules\Records\Actions\DecideRecordChangeSet;
use App\Modules\Records\Models\RecordChangeItem;
use App\Modules\Records\Models\RecordChangeSet;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * A submission of records; with its changes (`items`) when one is shown: each record's approved value beside the
 * proposed one, the record as it was for a removal.
 *
 * @mixin RecordChangeSet
 */
class RecordChangeSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'record_type' => ['value' => $this->record_type->value, 'label' => $this->record_type->label()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'items_count' => $this->items_count,
            'note' => $this->note,
            'submitted_by' => new StaffUserSummaryResource($this->submitter),
            'submitted_at' => $this->submitted_at->toIso8601String(),
            'self_approved' => $this->self_approved,
            'reviewed_by' => $this->reviewer ? new StaffUserSummaryResource($this->reviewer) : null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            /** Whether the signed-in user may approve or refuse it now. */
            'can_decide' => $this->isPending() && $user->hasPermission('entries.review') && $this->allowed(fn () => DecideRecordChangeSet::ensureCanDecide($user, $this->resource)),
            'items' => $this->whenLoaded('items', fn () => $this->items($this->resource)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function items(RecordChangeSet $set): array
    {
        $type = $set->record_type;
        $labels = (new RecordLabels)->prime($type, $set->items->flatMap(fn (RecordChangeItem $i) => [$i->old_values, $i->new_values]));

        return $set->items->map(function (RecordChangeItem $item) use ($type, $labels) {
            $keys = array_keys(($item->new_values ?? []) + ($item->old_values ?? []));
            $keys = array_values(array_intersect(RecordCatalogue::keys($type), $keys));

            return [
                'position' => $item->position,
                'action' => ['value' => $item->action->value, 'label' => $item->action->label()],
                'record_id' => $item->record_id,
                'employee' => [
                    'id' => $item->employee_id,
                    'personal_number' => $item->employee?->personal_number,
                    'full_name' => $item->employee?->fullName(),
                ],
                'fields' => array_map(fn (string $key) => [
                    'field' => $key,
                    'label' => RecordCatalogue::label($type, $key),
                    'old_value' => $item->old_values[$key] ?? null,
                    'old_display' => $labels->display($type, $key, $item->old_values[$key] ?? null),
                    'new_value' => $item->new_values[$key] ?? null,
                    'new_display' => $labels->display($type, $key, $item->new_values[$key] ?? null),
                ], $keys),
            ];
        })->values()->all();
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
