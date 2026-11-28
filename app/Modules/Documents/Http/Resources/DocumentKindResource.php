<?php

namespace App\Modules\Documents\Http\Resources;

use App\Modules\Documents\Support\DocumentKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A kind of document: one of the workbook's 13 attachments (with its field-guide settings) or the conduct
 * record's document.
 *
 * @mixin DocumentKind
 */
class DocumentKindResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            /** The workbook's column title («رابط …»). */
            'workbook_field' => $this->workbookField,
            'workbook_column' => $this->workbookColumn,
            /** As in the field guide (GET /field-definitions): editable there. */
            'cycle' => $this->cycle,
            'cycle_label' => $this->cycleLabel,
            'counts_for_completeness' => $this->countsForCompleteness,
            /** Attached to a conduct record (`conduct_record_id`), not to the file. */
            'on_conduct_record' => $this->onConductRecord,
        ];
    }
}
