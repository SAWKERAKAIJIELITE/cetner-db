<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Models\FieldDefinition;
use App\Modules\Employees\Support\FieldCatalogue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FieldDefinition
 */
class FieldDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The 13 attachments (D-049) are definitions without a catalogue field.
        $field = FieldCatalogue::has($this->key) ? FieldCatalogue::get($this->key) : [];

        return [
            'key' => $this->key,
            'label' => $this->label_ar,
            'section' => $this->section_ar,
            'workbook_column' => $this->workbook_column,
            /** text, longtext, name, code, digits, phone, email, date, year, rate, int, decimal, money, bool, list, org_unit; `document` for an attachment (filled when a current approved file of that kind is attached). */
            'type' => $this->type,
            /** For a list field: the list's key (GET /settings/lists/{key}). */
            'list' => $this->list_key,
            /** @var int|float|null */
            'min' => $field['min'] ?? null,
            /** @var int|float|null */
            'max' => $field['max'] ?? null,
            'cycle' => $this->cycle->value,
            'cycle_label' => $this->cycle->label(),
            'counts_for_completeness' => $this->counts_for_completeness,
            /** It identifies the person: a change needs the administrator's approval (D-034). */
            'identity' => $this->identity,
        ];
    }
}
