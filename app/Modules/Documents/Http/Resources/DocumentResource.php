<?php

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One upload of a document: what it is, who uploaded and decided it, and what became of it.
 *
 * @mixin EmployeeDocument
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $state = $this->state();

        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'personal_number' => $this->employee->personal_number,
                'full_name' => $this->employee->fullName(),
            ]),
            'kind' => $this->kind,
            'kind_label' => DocumentCatalogue::label($this->kind),
            /** For `conduct_document`: the conduct record it is attached to. */
            'conduct_record_id' => $this->conduct_record_id,
            /** pending, approved, rejected, withdrawn */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            /** pending, current, superseded (a newer one replaced it), removed, rejected, withdrawn */
            'state' => $state,
            'state_label' => EmployeeDocument::stateLabel($state),
            'original_name' => $this->original_name,
            /** application/pdf, image/jpeg, image/png or image/webp: read from the content. */
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            /** The content's SHA-256, to tell whether two files are the same. */
            'sha256' => $this->sha256,
            'note' => $this->note,
            'uploaded_by' => new StaffUserSummaryResource($this->uploader),
            'uploaded_at' => $this->uploaded_at->toIso8601String(),
            /** A reviewer's own upload, applied at once. */
            'self_approved' => $this->self_approved,
            'reviewed_by' => $this->reviewer ? new StaffUserSummaryResource($this->reviewer) : null,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'superseded_by_id' => $this->superseded_by_id,
            'removed_by' => $this->remover ? new StaffUserSummaryResource($this->remover) : null,
            'removed_at' => $this->removed_at?->toIso8601String(),
            'removal_reason' => $this->removal_reason,
            /** Its content is still kept: GET /documents/{id}/download works. Gone after a refusal, a withdrawal or a removal. */
            'content_available' => $this->hasContent(),
            'can_download' => $this->hasContent() && $user->hasPermission('documents.view'),
            /** Whether the signed-in user may approve or refuse it now (not their own). */
            'can_decide' => $this->isPending() && $user->hasPermission('entries.review') && $this->uploaded_by !== $user->id,
            'can_withdraw' => $this->isPending() && $this->uploaded_by === $user->id,
            /** The administrator may take a current document off the file. */
            'can_remove' => $this->isCurrent() && $user->hasPermission('documents.manage') && $user->hasPermission('entries.review'),
        ];
    }
}
