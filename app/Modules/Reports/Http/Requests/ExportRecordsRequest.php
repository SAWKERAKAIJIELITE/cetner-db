<?php

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Records\Http\Requests\RecordIndexRequest;

/** The register's filters and order (`GET /records/{type}`). */
class ExportRecordsRequest extends RecordIndexRequest
{
    public function rules(): array
    {
        return array_diff_key(parent::rules(), array_flip(['page', 'per_page']));
    }
}
