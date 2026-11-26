<?php

namespace App\Modules\Records\Support;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Support\EmployeeQuery;
use App\Modules\Records\Enums\DebtStatus;
use App\Modules\Records\Enums\RecordAction;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Models\EmployeeRecord;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A register as the design shows it (owner, 25 Nov; D-041): the approved records, and the additions waiting for
 * review («قيد التدقيق») read from their change items with the same columns, in one query. The waiting rows are
 * shown, never counted: every total, summary and report reads the record tables alone.
 */
final class RecordRegister
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    /** @var array<string, string> the sort keys of every register, then each kind's own */
    private const SORTS = ['date' => '', 'created_at' => 'created_at', 'employee' => 'employee'];

    /** @return list<string> */
    public static function sorts(RecordType $type): array
    {
        return [...array_keys(self::SORTS), ...array_keys(self::ownSorts($type))];
    }

    /** @return array<string, string> */
    private static function ownSorts(RecordType $type): array
    {
        return match ($type) {
            RecordType::Movements => ['amount' => 'amount', 'days' => 'days'],
            RecordType::Attendance => ['absent_days' => 'absent_days', 'absence_rate' => 'absence_rate'],
            RecordType::Debts => ['amount' => 'amount', 'remaining_amount' => 'remaining_amount', 'due_on' => 'due_on'],
            RecordType::Payments => ['amount' => 'amount'],
            RecordType::Conduct => [],
        };
    }

    /**
     * The register's query, filtered and sorted. `review_status`: approved, pending, or both when absent;
     * `deleted`: the removed records instead (approved ones only).
     *
     * @param  array<string, mixed>  $f
     * @return Builder<EmployeeRecord>
     */
    public static function query(RecordType $type, array $f = []): Builder
    {
        /** @var EmployeeRecord $model */
        $model = new ($type->model());
        $table = $model->getTable();

        if (! empty($f['deleted'])) {
            $query = $model->newQuery()->whereNotNull("{$table}.deleted_at")->select("{$table}.*")
                ->selectRaw("'approved' AS review_status, NULL::uuid AS change_set_id");
        } else {
            $status = $f['review_status'] ?? null;
            $approved = self::approved($type, $table);
            $pending = self::pending($type);
            $source = match ($status) {
                self::APPROVED => $approved,
                self::PENDING => $pending,
                default => $approved->unionAll($pending),
            };

            $query = $model->newQuery()->fromSub($source, $table)->select("{$table}.*");
        }

        self::filter($type, $query, $table, $f);
        self::sort($type, $query, $table, $f['sort'] ?? 'date', $f['direction'] ?? 'desc');

        return $query;
    }

    /** An approved record (removed ones too) or a waiting addition, with its review status. */
    public static function find(RecordType $type, string $id): ?EmployeeRecord
    {
        $table = $type->table();

        return $type->model()::query()->whereKey($id)->select("{$table}.*")
            ->selectRaw("'approved' AS review_status, NULL::uuid AS change_set_id")->first()
            ?? $type->model()::query()->fromSub(self::pending($type), $table)->select("{$table}.*")->where("{$table}.id", $id)->first();
    }

    /** The columns of a register row, in one order for both halves of the union. */
    private static function columns(RecordType $type): array
    {
        return ['id', 'employee_id', ...RecordCatalogue::keys($type), ...$type->generated(), ...$type->bookkeeping(),
            'created_by', 'approved_by', 'deleted_at', 'deleted_by', 'created_at', 'updated_at'];
    }

    private static function approved(RecordType $type, string $table): QueryBuilder
    {
        return DB::table($table)->whereNull('deleted_at')
            ->select(array_map(fn ($c) => "{$table}.{$c}", self::columns($type)))
            ->selectRaw("'approved' AS review_status, NULL::uuid AS change_set_id");
    }

    /** @var array<string, array<string, string>> table => column => its exact type */
    private static array $types = [];

    /**
     * Each column's exact type: the two halves of the union must match exactly, or PostgreSQL does not merge them
     * and cannot push a filter (a year, an employee) into the table's indexes.
     *
     * @return array<string, string>
     */
    private static function columnTypes(string $table): array
    {
        return self::$types[$table] ??= collect(DB::select(
            'SELECT attname, format_type(atttypid, atttypmod) AS type FROM pg_attribute WHERE attrelid = ?::regclass AND attnum > 0 AND NOT attisdropped',
            [$table],
        ))->pluck('type', 'attname')->all();
    }

    /** The waiting additions, as rows: each value cast from its change item, the computed columns computed. */
    private static function pending(RecordType $type): QueryBuilder
    {
        $fields = RecordCatalogue::fields($type);
        $types = self::columnTypes($type->table());
        $v = fn (string $key) => "(i.new_values->>'{$key}')";
        $cast = fn (string $key) => $v($key).'::'.$types[$key];
        $generated = [
            'days' => "CASE WHEN {$v('starts_on')} IS NOT NULL AND {$v('ends_on')} IS NOT NULL THEN {$v('ends_on')}::date - {$v('starts_on')}::date + 1 END",
            'absent_days' => "{$v('planned_days')}::numeric - {$v('attended_days')}::numeric",
            'payments_total' => '0::numeric',
            'remaining_amount' => "{$v('amount')}::numeric - coalesce({$v('paid_amount')}::numeric, 0)",
        ];

        $select = ['i.record_id AS id', 'i.employee_id'];

        foreach (array_keys($fields) as $key) {
            $select[] = $cast($key)." AS {$key}";
        }

        foreach ($type->generated() as $column) {
            $select[] = '('.$generated[$column].')::'.$types[$column]." AS {$column}";
        }

        foreach ($type->bookkeeping() as $column) {
            $select[] = 'NULL::'.$types[$column]." AS {$column}";
        }

        array_push($select, 's.submitted_by AS created_by', 'NULL::uuid AS approved_by', 'NULL::timestamptz AS deleted_at',
            'NULL::uuid AS deleted_by', 's.submitted_at AS created_at', 's.submitted_at AS updated_at',
            "'pending' AS review_status", 's.id AS change_set_id');

        // The waiting sets first, as an array the items' index takes (D-031): a join alone lets the planner scan every
        // change item ever made.
        return DB::table('record_change_items AS i')
            ->join('record_change_sets AS s', 's.id', '=', 'i.change_set_id')
            ->whereRaw('i.change_set_id = ANY (ARRAY(SELECT id FROM record_change_sets WHERE status = ? AND record_type = ?))', [ChangeSetStatus::Pending->value, $type->value])
            ->where('s.status', ChangeSetStatus::Pending->value)
            ->where('i.action', RecordAction::Create->value)
            ->selectRaw(implode(', ', $select));
    }

    /** @param array<string, mixed> $f */
    private static function filter(RecordType $type, Builder $query, string $table, array $f): void
    {
        $employees = fn (callable $where) => $query->whereIn("{$table}.employee_id", function ($sub) use ($where) {
            $sub->select('employees.id')->from('employees');
            $where($sub);
        });

        if (! empty($f['employee_id'])) {
            $query->where("{$table}.employee_id", $f['employee_id']);
        }

        if (! empty($f['personal_number'])) {
            $employees(fn ($e) => $e->where('employees.personal_number', $f['personal_number']));
        }

        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $employees(fn ($e) => EmployeeQuery::search($e, $q));
        }

        if (! empty($f['org_unit_id'])) {
            $units = ($f['include_sub_units'] ?? true) ? EmployeeQuery::unitAndBelow((int) $f['org_unit_id']) : [(int) $f['org_unit_id']];
            $employees(fn ($e) => $e->whereIn('employees.org_unit_id', $units));
        }

        if ($type === RecordType::Attendance) {
            $period = "({$table}.year * 100 + {$table}.month)";
            $query->when($f['year'] ?? null, fn ($q, $y) => $q->where("{$table}.year", $y))
                ->when($f['month'] ?? null, fn ($q, $m) => $q->where("{$table}.month", $m))
                // The period: by month, «from» and «to» being any day of the first and last month.
                ->when($f['from'] ?? null, fn ($q, $d) => $q->whereRaw("{$period} >= ?", [(int) substr($d, 0, 4) * 100 + (int) substr($d, 5, 2)]))
                ->when($f['to'] ?? null, fn ($q, $d) => $q->whereRaw("{$period} <= ?", [(int) substr($d, 0, 4) * 100 + (int) substr($d, 5, 2)]));

            if (isset($f['high_absence'])) {
                $query->whereRaw(($f['high_absence'] ? '' : 'NOT ')."({$table}.planned_days > 0 AND {$table}.absent_days / {$table}.planned_days >= ?)", [RecordComputed::thresholdFromSettings()]);
            }

            // Months without an absence («انضباط شهري تام»).
            if (isset($f['no_absence'])) {
                $query->where("{$table}.absent_days", $f['no_absence'] ? '=' : '>', 0);
            }
        } else {
            $date = "{$table}.".$type->dateColumn();
            $query->when($f['year'] ?? null, fn ($q, $y) => $q->whereBetween($date, [sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)]))
                ->when($f['month'] ?? null, fn ($q, $m) => $q->whereRaw("extract(month FROM {$date}) = ?", [$m]))
                ->when($f['from'] ?? null, fn ($q, $d) => $q->where($date, '>=', $d))
                ->when($f['to'] ?? null, fn ($q, $d) => $q->where($date, '<=', $d));
        }

        $list = ['movements' => 'movement_type_id', 'conduct' => 'conduct_type_id', 'debts' => 'debt_type_id', 'payments' => 'payment_method_id'][$type->value] ?? null;

        if ($list !== null && ! empty($f['type_id'])) {
            $query->where("{$table}.{$list}", $f['type_id']);
        }

        if ($type === RecordType::Conduct && ! empty($f['conduct_status_id'])) {
            $query->where("{$table}.conduct_status_id", $f['conduct_status_id']);
        }

        if ($type === RecordType::Debts) {
            if (($creditor = trim((string) ($f['creditor'] ?? ''))) !== '') {
                $query->where("{$table}.creditor", 'ilike', '%'.addcslashes($creditor, '%_\\').'%');
            }

            if (isset($f['debt_status'])) {
                $today = BusinessDay::today()->format('Y-m-d');
                match (DebtStatus::from($f['debt_status'])) {
                    DebtStatus::Paid => $query->where("{$table}.remaining_amount", '<=', 0),
                    DebtStatus::Overdue => $query->where("{$table}.remaining_amount", '>', 0)->where("{$table}.due_on", '<', $today),
                    DebtStatus::Outstanding => $query->where("{$table}.remaining_amount", '>', 0)
                        ->where(fn ($q) => $q->whereNull("{$table}.due_on")->orWhere("{$table}.due_on", '>=', $today)),
                };
            }
        }

        if ($type === RecordType::Payments && ! empty($f['debt_id'])) {
            $query->where("{$table}.debt_id", $f['debt_id']);
        }
    }

    private static function sort(RecordType $type, Builder $query, string $table, string $sort, string $direction): void
    {
        $direction = $direction === 'asc' ? 'asc' : 'desc';
        $nulls = $direction === 'asc' ? 'NULLS LAST' : 'NULLS FIRST';

        match (true) {
            $sort === 'employee' => $query->join('employees AS sort_employee', 'sort_employee.id', '=', "{$table}.employee_id")
                ->orderByRaw("sort_employee.search_name {$direction} {$nulls}"),
            $sort === 'absence_rate' => $query->orderByRaw("CASE WHEN {$table}.planned_days > 0 THEN {$table}.absent_days / {$table}.planned_days ELSE 0 END {$direction}"),
            $sort !== 'date' && isset(self::ownSorts($type)[$sort]), $sort === 'created_at' => $query->orderByRaw("{$table}.{$sort} {$direction} {$nulls}"),
            default => array_map(fn ($c) => $query->orderByRaw("{$table}.{$c} {$direction} {$nulls}"), $type->order()),
        };

        $query->orderBy("{$table}.created_at", $direction)->orderBy("{$table}.id");
    }
}
