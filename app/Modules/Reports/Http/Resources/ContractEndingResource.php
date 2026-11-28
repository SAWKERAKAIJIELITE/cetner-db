<?php

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeComputed;
use App\Modules\Employees\Support\ValueLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file whose contract ends soon: «الرقم الذاتي»، «الإسم»، «جهة العمل»، «تاريخ الإنتهاء»، «المتبقي».
 *
 * @mixin Employee
 */
class ContractEndingResource extends JsonResource
{
    /** Set for each page by the controller: one lookup of the labels for the page. */
    public static ?ValueLabels $labels = null;

    public function toArray(Request $request): array
    {
        self::$labels ??= new ValueLabels;

        return [
            'id' => $this->id,
            'personal_number' => $this->personal_number,
            'full_name' => $this->fullName(),
            'org_unit' => self::$labels->ref('org_unit_id', $this->org_unit_id),
            'job_title' => self::$labels->ref('job_title_id', $this->job_title_id),
            'contract_ends_on' => $this->contract_ends_on?->format('Y-m-d'),
            /** Days left, today in Damascus. */
            'days_left' => EmployeeComputed::contractDaysLeft($this->resource),
        ];
    }
}
