<?php

namespace App\Modules\Records\Models;

use App\Modules\Records\Enums\RecordType;

/** «سجل الدوام الشهري»: one month of one employee; the absent days are the database's. */
class EmployeeAttendance extends EmployeeRecord
{
    protected $table = 'employee_attendance';

    public function recordType(): RecordType
    {
        return RecordType::Attendance;
    }
}
