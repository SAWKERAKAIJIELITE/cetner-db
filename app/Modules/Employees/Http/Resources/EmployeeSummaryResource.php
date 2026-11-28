<?php

namespace App\Modules\Employees\Http\Resources;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeComputed;
use App\Modules\Employees\Support\ValueLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the employees list: who, where, status, contract, completeness, overdue cycles, pending changes.
 *
 * @mixin Employee
 */
class EmployeeSummaryResource extends JsonResource
{
    public static ?ValueLabels $labels = null;

    /** @var array<string, array<string, array{overdue: bool}>> */
    public static array $reviews = [];

    /** @var array<string, int> */
    public static array $pending = [];

    /** @var array<string, string> employee id => what is left of their debts */
    public static array $debts = [];

    public function toArray(Request $request): array
    {
        $labels = self::$labels ??= new ValueLabels;
        $reviews = self::$reviews[$this->id] ?? [];

        return [
            'id' => $this->id,
            'file_status' => $this->file_status->value,
            'file_status_label' => $this->file_status->label(),
            'personal_number' => $this->personal_number,
            'national_number' => $this->national_number,
            'full_name' => $this->fullName(),
            'identity_locked' => $this->identity_locked_at !== null,
            /** `{id, name_ar, archived}`, name_ar being the unit's path (the register's «جهة العمل الحالية»). */
            'org_unit' => $labels->ref('org_unit_id', $this->org_unit_id),
            'employee_status' => $labels->ref('employee_status_id', $this->employee_status_id),
            'job_status' => $labels->ref('job_status_id', $this->job_status_id),
            'job_title' => $labels->ref('job_title_id', $this->job_title_id),
            /** «تاريخ التقديم»: the day the file was approved. */
            'application_date' => $this->activated_at?->setTimezone(config('hr.timezone'))->format('Y-m-d'),
            'contract_ends_on' => $this->contract_ends_on?->format('Y-m-d'),
            'contract_days_left' => EmployeeComputed::contractDaysLeft($this->resource),
            'completeness' => (float) $this->completeness,
            'missing_count' => array_sum(array_map('count', $this->missing_fields ?? [])),
            /** @var list<string> */
            'overdue_cycles' => array_keys(array_filter($reviews, fn ($r) => $r['overdue'])),
            'pending_changes' => self::$pending[$this->id] ?? 0,
            /** «إجمالي الديون المتبقية»: 0.00 when nothing is owed (D-044). */
            'debts_remaining' => self::$debts[$this->id] ?? '0.00',
        ];
    }
}
