<?php

namespace App\Modules\Records\Http\Resources;

use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Models\EmployeeRecord;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordComputed;
use App\Modules\Records\Support\RecordLabels;
use App\Modules\Records\Support\RecordValues;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A record with its employee (number, name, unit, job title: the workbook's green columns), each field's value
 * and how it reads, the computed columns, and the changes waiting for it beside the approved values.
 *
 * @mixin EmployeeRecord
 */
class RecordResource extends JsonResource
{
    public static ?RecordLabels $labels = null;

    public static ?ValueLabels $employeeLabels = null;

    /** @var array<string, list<array<string, mixed>>> record id => its pending changes */
    public static array $pending = [];

    /** @var array<string, array{current: array<string, mixed>|null, pending: int}> conduct record id => its document (D-049) */
    public static array $documents = [];

    public function toArray(Request $request): array
    {
        $type = $this->recordType();
        $labels = self::$labels ??= new RecordLabels;
        $employeeLabels = self::$employeeLabels ??= new ValueLabels;
        $values = RecordValues::of($this->resource);
        $employee = $this->employee;

        $fields = [];

        foreach (RecordCatalogue::fields($type) as $key => $field) {
            $fields[$key] = [
                'label' => $field['label'],
                'value' => $values[$key],
                'display' => $labels->display($type, $key, $values[$key]),
                /** A list or unit value as `{id, name_ar, archived}`; null otherwise. */
                'item' => $labels->ref($type, $key, $values[$key]),
            ];
        }

        return [
            'id' => $this->id,
            'record_type' => $type->value,
            'employee' => [
                'id' => $this->employee_id,
                'personal_number' => $employee?->personal_number,
                'full_name' => $employee?->fullName(),
                'org_unit' => $employeeLabels->ref('org_unit_id', $employee?->org_unit_id),
                'job_title' => $employeeLabels->ref('job_title_id', $employee?->job_title_id),
            ],
            /** approved («معتمد») or pending («قيد التدقيق»: an addition waiting for review, counted nowhere yet). */
            'review_status' => $pending = ($this->getAttribute('review_status') ?? 'approved'),
            'review_status_label' => $pending === 'pending' ? 'قيد التدقيق' : 'معتمد',
            /** The submission a waiting addition is in. */
            'change_set_id' => $this->getAttribute('change_set_id'),
            'fields' => $fields,
            'computed' => RecordComputed::of($this->resource),
            'deleted' => $this->isDeleted(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_by' => $this->creator ? new StaffUserSummaryResource($this->creator) : null,
            'approved_by' => $this->approver ? new StaffUserSummaryResource($this->approver) : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            /** Changes sent for this record and not decided yet: the approved value stays until one is approved. */
            'pending' => self::$pending[$this->id] ?? [],
            /**
             * A conduct record's document (D-049): the current one (or null) and how many wait for review. Upload with
             * POST /employees/{id}/documents, kind `conduct_document`. Absent from the other records.
             *
             * @var array{current: array{id: string, original_name: string, mime: string, size_bytes: int, uploaded_at: string}|null, pending: int}
             */
            'document' => $this->when($type === RecordType::Conduct, fn () => self::$documents[$this->id] ?? ['current' => null, 'pending' => 0]),
        ];
    }
}
