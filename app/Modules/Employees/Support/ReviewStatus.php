<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\EmployeeReview;
use App\Modules\Settings\Models\WorkSettings;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The four cycles of a file: the last confirmation, when the next is due, and whether it is overdue, as the
 * workbook colours «آخر مراجعة» (D-036): overdue when never confirmed or confirmed longer ago than the
 * cycle's window.
 */
final class ReviewStatus
{
    /**
     * @param  list<string>  $employeeIds
     * @return array<string, array<string, array{label: string, period: string, last_reviewed_on: ?string, last_period: ?string, due_on: ?string, overdue: bool, current_period_done: bool}>>
     */
    public static function forMany(array $employeeIds): array
    {
        $today = BusinessDay::today();
        $settings = WorkSettings::current();
        $last = [];

        if ($employeeIds !== []) {
            $rows = DB::table('employee_reviews')
                ->select('employee_id', 'cycle', DB::raw('max(reviewed_on) as reviewed_on'))
                ->whereIn('employee_id', $employeeIds)
                ->groupBy('employee_id', 'cycle')
                ->get();

            foreach ($rows as $row) {
                $last[$row->employee_id][$row->cycle] = $row->reviewed_on;
            }

            $periods = EmployeeReview::query()->whereIn('employee_id', $employeeIds)
                ->get(['employee_id', 'cycle', 'period', 'reviewed_on'])
                ->groupBy(fn ($r) => $r->employee_id.'|'.$r->cycle->value);
        }

        $result = [];

        foreach ($employeeIds as $id) {
            foreach (Cycle::reviewed() as $cycle) {
                $window = (int) $settings->getAttribute($cycle->windowColumn());
                $lastOn = $last[$id][$cycle->value] ?? null;
                $due = $lastOn === null ? null : CarbonImmutable::parse($lastOn)->addDays($window);
                $current = $cycle->periodOf($today);
                $mine = ($periods ?? collect())->get($id.'|'.$cycle->value, collect());

                $result[$id][$cycle->value] = [
                    'label' => $cycle->label(),
                    'period' => $current,
                    'last_reviewed_on' => $lastOn === null ? null : substr((string) $lastOn, 0, 10),
                    'last_period' => $mine->sortByDesc('reviewed_on')->first()?->period,
                    'due_on' => $due?->format('Y-m-d'),
                    'overdue' => $due === null || $due->lessThan($today),
                    'current_period_done' => $mine->contains('period', $current),
                ];
            }
        }

        return $result;
    }

    /** @return array<string, array{label: string, period: string, last_reviewed_on: ?string, last_period: ?string, due_on: ?string, overdue: bool, current_period_done: bool}> */
    public static function of(string $employeeId): array
    {
        return self::forMany([$employeeId])[$employeeId];
    }
}
