<?php

namespace App\Modules\Employees\Http\Requests;

use App\Modules\Employees\Enums\FileStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /** A name (any spelling), or the start of a personal or national number (any digits). */
            'q' => ['nullable', 'string', 'max:100'],
            'org_unit_id' => ['nullable', 'integer'],
            /** With the units under it (default true). */
            'include_sub_units' => ['nullable', 'boolean'],
            'employee_status_id' => ['nullable', 'integer'],
            'job_status_id' => ['nullable', 'integer'],
            /** active (default), pending (added, waiting), rejected, withdrawn */
            'file_status' => ['nullable', Rule::enum(FileStatus::class)],
            /** Contracts ending from today to this many days ahead. */
            'contract_ends_within_days' => ['nullable', 'integer', 'between:0,3650'],
            /** Files whose cycle is overdue: monthly, quarterly, semi_annual, annual */
            'overdue_cycle' => ['nullable', 'in:monthly,quarterly,semi_annual,annual'],
            'completeness_below' => ['nullable', 'numeric', 'between:0,100'],
            /** Locked (1) or not yet (0): the administrator's list of identities to confirm. */
            'identity_locked' => ['nullable', 'boolean'],
            /** «عليه ديون»: with something left to pay in the debts record. */
            'has_debts' => ['nullable', 'boolean'],
            /** Files with a change waiting for review. */
            'has_pending' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,completeness,contract_ends_on,created_at'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ];
    }
}
