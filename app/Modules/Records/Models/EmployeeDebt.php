<?php

namespace App\Modules\Records\Models;

use App\Modules\Records\Enums\RecordType;

/** «سجل الديون»: what is left is the database's, the status is computed (D-042). */
class EmployeeDebt extends EmployeeRecord
{
    protected $table = 'employee_debts';

    public function recordType(): RecordType
    {
        return RecordType::Debts;
    }
}
