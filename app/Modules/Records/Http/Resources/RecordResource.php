<?php

namespace App\Modules\Records\Http\Resources;

use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
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
            ];
        }

        return [
            'id' => $this->id,
            'record_type' => $type->value,
            'employee' => [
                'id' => $this->employee_id,
                'personal_number' => $employee?->personal_number,
                'full_name' => $employee?->fullName(),
                'org_unit' => $employeeLabels->unitPath($employee?->org_unit_id),
                'job_title' => $employeeLabels->item('job_title_id', $employee?->job_title_id)['label'] ?? null,
            ],
            'fields' => $fields,
            'computed' => RecordComputed::of($this->resource),
            'deleted' => $this->isDeleted(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_by' => new StaffUserSummaryResource($this->whenLoaded('creator')),
            'approved_by' => new StaffUserSummaryResource($this->whenLoaded('approver')),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            /** Changes sent for this record and not decided yet: the approved value stays until one is approved. */
            'pending' => self::$pending[$this->id] ?? [],
        ];
    }
}
