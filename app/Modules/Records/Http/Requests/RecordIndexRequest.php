<?php

namespace App\Modules\Records\Http\Requests;

use App\Modules\Records\Enums\DebtStatus;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\RecordRegister;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordIndexRequest extends FormRequest
{
    public function rules(): array
    {
        $type = RecordType::tryFrom((string) $this->route('type')) ?? RecordType::Movements;

        return [
            /** The employee: a name (any spelling), or the start of a personal or national number. */
            'q' => ['nullable', 'string', 'max:100'],
            'employee_id' => ['nullable', 'uuid'],
            /** The employee's الرقم الذاتي, exactly. */
            'personal_number' => ['nullable', 'string', 'max:20'],
            /** The employee's unit, with the units under it unless include_sub_units=0. */
            'org_unit_id' => ['nullable', 'integer', 'min:1'],
            'include_sub_units' => ['nullable', 'boolean'],
            /** approved («معتمد»), pending («قيد التدقيق»: an addition waiting for review); both when absent. */
            'review_status' => ['nullable', Rule::in([RecordRegister::APPROVED, RecordRegister::PENDING])],
            /** The year of the record's date (of the month, for the attendance). */
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            /** The period, Damascus days YYYY-MM-DD (for the attendance: the months of these days). */
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            /** The record's list: movement type, conduct type, debt type, payment method. */
            'type_id' => ['nullable', 'integer', 'min:1'],
            /** Conduct: «حالة المعالجة». */
            'conduct_status_id' => ['nullable', 'integer', 'min:1'],
            /** Debts: paid, overdue, outstanding. */
            'debt_status' => ['nullable', Rule::enum(DebtStatus::class)],
            /** Debts: part of the creditor's name. */
            'creditor' => ['nullable', 'string', 'max:200'],
            /** Payments: of one debt. */
            'debt_id' => ['nullable', 'uuid'],
            /** Attendance: months at or above the branch's absence threshold. */
            'high_absence' => ['nullable', 'boolean'],
            /** Attendance: months without an absence. */
            'no_absence' => ['nullable', 'boolean'],
            /** Removed records instead of the kept ones. */
            'deleted' => ['nullable', 'boolean'],
            /** date (default), created_at, employee, and each register's own: amount, days, absent_days, absence_rate, remaining_amount, due_on */
            'sort' => ['nullable', Rule::in(RecordRegister::sorts($type))],
            /** asc or desc (default). */
            'direction' => ['nullable', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ];
    }
}
