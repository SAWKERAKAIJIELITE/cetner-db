<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Settings\Models\WorkSettings;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** The employees list's filters and order, all in SQL on indexed columns (D-031). */
final class EmployeeQuery
{
    /** @param array<string, mixed> $f validated filters */
    public static function build(array $f): Builder
    {
        $today = BusinessDay::today();
        $query = Employee::query()->where('file_status', $f['file_status'] ?? 'active');

        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            self::search($query, $q);
        }

        if (! empty($f['org_unit_id'])) {
            $ids = ($f['include_sub_units'] ?? true) ? self::unitAndBelow((int) $f['org_unit_id']) : [(int) $f['org_unit_id']];
            $query->whereIn('org_unit_id', $ids);
        }

        foreach (['employee_status_id', 'job_status_id', 'work_schedule_id', 'position_level_id', 'org_stage_id', 'gender_id'] as $field) {
            if (! empty($f[$field])) {
                $query->where($field, (int) $f[$field]);
            }
        }

        if (isset($f['contract_ends_within_days'])) {
            $query->whereBetween('contract_ends_on', [$today->format('Y-m-d'), $today->addDays((int) $f['contract_ends_within_days'])->format('Y-m-d')]);
        }

        if (! empty($f['overdue_cycle'])) {
            $cycle = Cycle::from($f['overdue_cycle']);
            $since = $today->subDays((int) WorkSettings::current()->getAttribute($cycle->windowColumn()))->format('Y-m-d');
            $query->whereNotExists(fn ($r) => $r->from('employee_reviews')
                ->whereColumn('employee_reviews.employee_id', 'employees.id')
                ->where('employee_reviews.cycle', $cycle->value)
                ->where('employee_reviews.reviewed_on', '>=', $since));
        }

        // «عليه ديون»: something left to pay in the debts record (D-044).
        if (isset($f['has_debts'])) {
            $debts = fn ($q) => $q->selectRaw('1')->from('employee_debts')->whereColumn('employee_debts.employee_id', 'employees.id')
                ->whereNull('employee_debts.deleted_at')->where('employee_debts.remaining_amount', '>', 0);
            $f['has_debts'] ? $query->whereExists($debts) : $query->whereNotExists($debts);
        }

        if (isset($f['identity_locked'])) {
            $f['identity_locked'] ? $query->whereNotNull('identity_locked_at') : $query->whereNull('identity_locked_at');
        }

        if (isset($f['completeness_below'])) {
            $query->where('completeness', '<', $f['completeness_below']);
        }

        if (! empty($f['has_pending'])) {
            $query->whereExists(fn ($s) => $s->from('employee_change_sets')
                ->whereColumn('employee_change_sets.employee_id', 'employees.id')
                ->where('employee_change_sets.status', ChangeSetStatus::Pending->value));
        }

        $sort = $f['sort'] ?? 'name';
        $direction = ($f['direction'] ?? ($sort === 'created_at' ? 'desc' : 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $column = match ($sort) {
            'personal_number', 'completeness', 'contract_ends_on', 'created_at' => $sort,
            default => 'search_name',
        };

        return $query->orderByRaw("{$column} {$direction} NULLS LAST")->orderBy('id');
    }

    /**
     * A name (any spelling) or the start of a personal or national number (any digits), on a query of `employees`.
     *
     * @param  Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function search($query, string $text): void
    {
        $digits = ArabicText::digitsToAscii(trim($text));

        if (preg_match('/^[0-9]+$/', $digits) === 1) {
            $query->where(fn ($w) => $w->where('employees.personal_number', 'like', $digits.'%')->orWhere('employees.national_number', 'like', $digits.'%'));
        } else {
            $query->where('employees.search_name', 'ilike', '%'.addcslashes(ArabicText::searchKey($text), '%_\\').'%');
        }
    }

    /** @return list<int> a unit and every unit under it */
    public static function unitAndBelow(int $id): array
    {
        return array_map('intval', array_column(DB::select(<<<'SQL'
            WITH RECURSIVE below AS (
                SELECT id FROM org_units WHERE id = ?
                UNION ALL
                SELECT u.id FROM org_units u JOIN below b ON u.parent_id = b.id
            )
            SELECT id FROM below
            SQL, [$id]), 'id'));
    }
}
