<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\DebtStatus;
use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Records\Models\EmployeeConductRecord;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Settings\Models\WorkSettings;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «مؤشرات السنة المختارة (من السجلات)» of «تقرير الحالة الوظيفية» (D-044), computed when read with the workbook's
 * rules: a leave is a movement whose type begins with «إجازة»; courses, rewards and deductions, complaints,
 * warnings, punishments and thanks are found by their list items' codes, so renaming them changes nothing. The
 * debts are all of them, not the year's. A fixed number of queries.
 */
final class RecordSummary
{
    /** @return array<string, mixed> */
    public static function of(string $employeeId, int $year): array
    {
        [$from, $to] = [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)];

        // «إجازة», «اجازة», «إجازه»: one word (the lists' search key).
        $leave = ArabicText::searchKey('إجازة').'%';

        $m = DB::table('employee_movements AS m')->join('movement_types AS t', 't.id', '=', 'm.movement_type_id')
            ->where('m.employee_id', $employeeId)->whereNull('m.deleted_at')->whereBetween('m.occurred_on', [$from, $to])
            ->selectRaw(<<<'SQL'
                count(*) FILTER (WHERE t.search_key LIKE ?) AS leaves,
                coalesce(sum(m.days) FILTER (WHERE t.search_key LIKE ?), 0) AS leave_days,
                count(*) FILTER (WHERE t.code = 'course') AS courses,
                coalesce(sum(m.amount) FILTER (WHERE t.code = 'reward'), 0) AS rewards,
                coalesce(sum(m.amount) FILTER (WHERE t.code = 'deduction'), 0) AS deductions
                SQL, [$leave, $leave])->first();

        $a = DB::table('employee_attendance')->where('employee_id', $employeeId)->whereNull('deleted_at')->where('year', $year)
            ->selectRaw('coalesce(sum(planned_days), 0) AS planned, coalesce(sum(attended_days), 0) AS attended, coalesce(sum(absent_days), 0) AS absent, count(*) AS months')
            ->first();

        $c = DB::table('employee_conduct_records AS c')->join('conduct_types AS t', 't.id', '=', 'c.conduct_type_id')
            ->where('c.employee_id', $employeeId)->whereNull('c.deleted_at')->whereBetween('c.occurred_on', [$from, $to])
            ->selectRaw(<<<'SQL'
                count(*) FILTER (WHERE t.code = 'complaint') AS complaints,
                count(*) FILTER (WHERE t.code = 'warning') AS warnings,
                count(*) FILTER (WHERE t.code = 'punishment') AS punishments,
                count(*) FILTER (WHERE t.code = 'thanks') AS thanks,
                count(*) AS total
                SQL)->first();

        $d = DB::table('employee_debts')->where('employee_id', $employeeId)->whereNull('deleted_at')
            ->selectRaw(<<<'SQL'
                count(*) FILTER (WHERE remaining_amount > 0) AS open,
                count(*) FILTER (WHERE remaining_amount > 0 AND due_on < ?) AS overdue,
                coalesce(sum(remaining_amount) FILTER (WHERE remaining_amount > 0), 0) AS remaining
                SQL, [BusinessDay::today()->format('Y-m-d')])->first();

        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        return [
            'year' => $year,
            'leaves' => [
                'count' => (int) $m->leaves,
                'days' => (int) $m->leave_days,
                /** The year's allowance («رصيد الإجازات») less the leave days, every kind of leave counted, as in the workbook. */
                'balance' => WorkSettings::current()->annual_leave_days - (int) $m->leave_days,
            ],
            'courses' => (int) $m->courses,
            'rewards_total' => $money($m->rewards),
            'deductions_total' => $money($m->deductions),
            'attendance' => [
                'months' => (int) $a->months,
                'planned_days' => (int) $a->planned,
                'attended_days' => (int) $a->attended,
                'absent_days' => (int) $a->absent,
                'absence_rate' => RecordComputed::absenceRate((int) $a->planned, (int) $a->absent),
            ],
            'conduct' => [
                'complaints' => (int) $c->complaints,
                'warnings' => (int) $c->warnings,
                'punishments' => (int) $c->punishments,
                'thanks' => (int) $c->thanks,
                'total' => (int) $c->total,
            ],
            'debts' => [
                'has_debts' => (float) $d->remaining > 0,
                'total_remaining' => $money($d->remaining),
                'open' => (int) $d->open,
                'overdue' => (int) $d->overdue,
            ],
        ];
    }

    /**
     * The report's lists: the last ten movements, the last five conduct records, the last month of attendance.
     * Newest by date, then by entry.
     *
     * @return array{movements: Collection, conduct: Collection, attendance: ?EmployeeAttendance}
     */
    public static function latest(string $employeeId): array
    {
        return [
            'movements' => EmployeeMovement::query()->kept()->where('employee_id', $employeeId)
                ->orderByDesc('occurred_on')->orderByDesc('created_at')->limit(10)->get(),
            'conduct' => EmployeeConductRecord::query()->kept()->where('employee_id', $employeeId)
                ->orderByDesc('occurred_on')->orderByDesc('created_at')->limit(5)->get(),
            'attendance' => EmployeeAttendance::query()->kept()->where('employee_id', $employeeId)
                ->orderByDesc('year')->orderByDesc('month')->first(),
        ];
    }

    /** @return list<string> */
    public static function debtStatuses(): array
    {
        return array_map(fn (DebtStatus $s) => $s->value, DebtStatus::cases());
    }
}
