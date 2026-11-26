<?php

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Employees\Http\Requests\EmployeeIndexRequest;

/** The employees list's filters and order (`GET /employees`), applied to the approved files. */
class ExportEmployeesRequest extends EmployeeIndexRequest
{
    public function rules(): array
    {
        return array_diff_key(parent::rules(), array_flip(['file_status', 'page', 'per_page']));
    }
}
