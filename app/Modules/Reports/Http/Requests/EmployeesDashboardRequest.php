<?php

namespace App\Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** «داش بورد الموظفين»: its six filters (none means «الكل»), and the unit. */
class EmployeesDashboardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** «المرحلة التنظيمية» */
            'org_stage_id' => ['nullable', 'integer'],
            /** «المستوى التنظيمي (المنصب)» */
            'position_level_id' => ['nullable', 'integer'],
            /** «حالة الموظف» */
            'employee_status_id' => ['nullable', 'integer'],
            /** «الحالة الوظيفية» */
            'job_status_id' => ['nullable', 'integer'],
            /** «الجنس» */
            'gender_id' => ['nullable', 'integer'],
            /** «نوع الدوام» */
            'work_schedule_id' => ['nullable', 'integer'],
            'org_unit_id' => ['nullable', 'integer'],
            'include_sub_units' => ['nullable', 'boolean'],
        ];
    }
}
