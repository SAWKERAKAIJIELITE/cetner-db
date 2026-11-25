<?php

namespace App\Modules\Records\Models;

use App\Modules\Records\Enums\RecordType;

/** «سجل الحركات»: a leave, a reward, a deduction, a transfer, a promotion, a course. */
class EmployeeMovement extends EmployeeRecord
{
    protected $table = 'employee_movements';

    public function recordType(): RecordType
    {
        return RecordType::Movements;
    }
}
