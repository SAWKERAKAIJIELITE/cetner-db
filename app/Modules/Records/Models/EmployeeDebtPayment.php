<?php

namespace App\Modules\Records\Models;

use App\Modules\Records\Enums\RecordType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «تسجيل دفعة مالية»: one payment of a debt (D-045). Never edited; the debt's total is the database's. */
class EmployeeDebtPayment extends EmployeeRecord
{
    protected $table = 'employee_debt_payments';

    public function recordType(): RecordType
    {
        return RecordType::Payments;
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(EmployeeDebt::class, 'debt_id');
    }
}
