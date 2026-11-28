<?php

namespace App\Modules\Reports\Http\Requests;

class ExportMovementsSummaryRequest extends MovementsEmployeesRequest
{
    public function rules(): array
    {
        return array_diff_key(parent::rules(), array_flip(['page', 'per_page']));
    }
}
