<?php

namespace App\Modules\Reports\Support;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Support\EmployeeQuery;
use App\Modules\Settings\Models\WorkSettings;
use App\Support\BusinessDay;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The approved files a report counts (D-051), with the employees list's filters (EmployeeQuery), and the per-file
 * figures the reports join to them: what is left of the debts, the current attachments, the last review of each
 * cycle. Each is one grouped subquery, so a report costs a fixed number of queries whatever the number of files.
 */
final class EmployeeScope
{
    /** @param array<string, mixed> $filters */
    public static function files(array $filters): Builder
    {
        return EmployeeQuery::build(array_merge($filters, ['file_status' => 'active']))->reorder()->toBase();
    }

    /** employee_id, remaining: what is left of the kept debts, for files that owe something. */
    public static function debts(): Builder
    {
        return DB::table('employee_debts')->whereNull('deleted_at')->where('remaining_amount', '>', 0)
            ->groupBy('employee_id')->select('employee_id')->selectRaw('sum(remaining_amount) AS remaining');
    }

    /** employee_id, attached (of the 13), has_portrait: the current approved files (D-049). */
    public static function documents(): Builder
    {
        return DB::table('employee_documents')->whereNull('conduct_record_id')
            ->where('status', ChangeSetStatus::Approved->value)->whereNull('superseded_at')->whereNull('removed_at')
            ->groupBy('employee_id')->select('employee_id')
            ->selectRaw('count(*) AS attached, bool_or(kind = \'portrait\') AS has_portrait');
    }

    /** employee_id and the last review day of each reviewed cycle (columns named as the cycles). */
    public static function reviews(): Builder
    {
        $query = DB::table('employee_reviews')->groupBy('employee_id')->select('employee_id');

        foreach (Cycle::reviewed() as $cycle) {
            $query->selectRaw("max(reviewed_on) FILTER (WHERE cycle = '{$cycle->value}') AS {$cycle->value}");
        }

        return $query;
    }

    /**
     * The day before which a cycle's last review is overdue (D-036): today less the cycle's window.
     *
     * @return array<string, string> cycle => Y-m-d
     */
    public static function overdueBefore(): array
    {
        $today = BusinessDay::today();
        $settings = WorkSettings::current();
        $out = [];

        foreach (Cycle::reviewed() as $cycle) {
            $out[$cycle->value] = $today->subDays((int) $settings->getAttribute($cycle->windowColumn()))->format('Y-m-d');
        }

        return $out;
    }
}
