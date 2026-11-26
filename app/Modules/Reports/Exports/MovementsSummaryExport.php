<?php

namespace App\Modules\Reports\Exports;

use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Reports\Support\MovementsByEmployee;
use App\Modules\Reports\Support\ReportPeriod;

/** «ملخص جميع الموظفين» of «داش بورد الحركات» as an Excel file (D-052), with the screen's filters and order. */
final class MovementsSummaryExport
{
    private const PAGE = 500;

    /** @return list<string> */
    public static function headers(): array
    {
        return ['الرقم الذاتي', 'الاسم', 'المسمى', 'الحالة الوظيفية', 'إجازات داخلية', 'إجازات خارجية', 'أيام الإجازات',
            'رصيد الإجازات (السنوي)', 'مكافآت', 'إجمالي المكافآت', 'خصومات', 'إجمالي الخصومات', 'نقل', 'صافي',
            'إجمالي الحركات', 'أيام الحضور', 'نسبة الغياب', 'إنذارات', 'تقييم'];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Generator<list<string|int|float|null>>
     */
    public static function rows(ReportPeriod $period, array $filters): \Generator
    {
        $labels = new ValueLabels;
        $page = 1;

        do {
            $batch = MovementsByEmployee::query($period, $filters)->forPage($page++, self::PAGE)->get();

            foreach ($batch as $e) {
                yield [
                    $e->personal_number,
                    $e->fullName(),
                    $labels->display('job_title_id', $e->job_title_id),
                    $labels->display('job_status_id', $e->job_status_id),
                    (int) $e->internal_leaves,
                    (int) $e->external_leaves,
                    (int) $e->leave_days,
                    (int) $e->leave_balance,
                    (int) $e->rewards,
                    (float) $e->rewards_amount,
                    (int) $e->deductions,
                    (float) $e->deductions_amount,
                    (int) $e->transfers,
                    (float) $e->net,
                    (int) $e->movements,
                    (float) $e->attended_days,
                    (float) $e->absence_rate,
                    (int) $e->warnings,
                    MovementsByEmployee::rating((float) $e->net)[1],
                ];
            }
        } while ($batch->count() === self::PAGE);
    }
}
