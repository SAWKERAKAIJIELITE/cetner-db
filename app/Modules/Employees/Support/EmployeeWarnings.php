<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\Employee;

/**
 * The workbook's red and orange cells as warnings (D-037): shown on the file, never blocking a save,
 * because the missing piece is often not known yet.
 */
final class EmployeeWarnings
{
    public const CONTRACT_SOON_DAYS = 30;

    /**
     * @param  array<string, array{overdue: bool}>  $reviews  ReviewStatus::of()
     * @return list<array{code: string, field: ?string, message: string}>
     */
    public static function of(Employee $employee, array $reviews): array
    {
        $warnings = [];
        $jobStatus = EmployeeComputed::code($employee, 'job_status_id');
        $status = EmployeeComputed::code($employee, 'employee_status_id');

        if ($jobStatus === 'discharged' && $employee->discharge_date === null) {
            $warnings[] = ['code' => 'discharge_date_missing', 'field' => 'discharge_date', 'message' => __('employees.warning_discharge_date_missing')];
        }

        if ($status === 'active' && in_array($jobStatus, ['discharged', 'fled', 'retired'], true)) {
            $warnings[] = ['code' => 'active_but_left', 'field' => 'employee_status_id', 'message' => __('employees.warning_active_but_left')];
        }

        $days = EmployeeComputed::contractDaysLeft($employee);

        if ($days !== null && $days < 0) {
            $warnings[] = ['code' => 'contract_ended', 'field' => 'contract_ends_on', 'message' => __('employees.warning_contract_ended', ['days' => -$days])];
        } elseif ($days !== null && $days <= self::CONTRACT_SOON_DAYS) {
            $warnings[] = ['code' => 'contract_ending', 'field' => 'contract_ends_on', 'message' => __('employees.warning_contract_ending', ['days' => $days])];
        }

        foreach ($reviews as $cycle => $review) {
            if ($review['overdue']) {
                $warnings[] = ['code' => 'review_overdue', 'field' => null, 'message' => __('employees.warning_review_overdue', ['cycle' => $review['label']]), 'cycle' => $cycle];
            }
        }

        return $warnings;
    }
}
