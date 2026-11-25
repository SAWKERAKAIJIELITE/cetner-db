<?php

namespace App\Modules\Records\Models;

use App\Modules\Records\Enums\RecordType;

/** «سجل السلوك الوظيفي»: a complaint, a warning, a punishment, a thanks, and its decision. */
class EmployeeConductRecord extends EmployeeRecord
{
    protected $table = 'employee_conduct_records';

    public function recordType(): RecordType
    {
        return RecordType::Conduct;
    }
}
