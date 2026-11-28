<?php

namespace App\Modules\Records\Actions;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Enums\RecordAction;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Records\Models\EmployeeRecord;
use App\Modules\Records\Models\RecordChangeSet;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordValues;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves a pending set and writes its changes: the one place a record is written (D-041). Called inside the
 * caller's transaction, with the set locked. All of a set or none: a record changed or removed since the set was
 * sent makes it stale, and it is refused, not merged, so a reviewer never approves a change against values they
 * did not see.
 */
final class ApplyRecordChangeSet
{
    public function __construct(private readonly ApplyMovementToFile $movements) {}

    public function handle(RecordChangeSet $set, StaffUser $reviewer, bool $selfApproved, ?string $note = null): void
    {
        $type = $set->record_type;
        $items = $set->items()->get();

        $ids = $items->where('action', '!=', RecordAction::Create)->pluck('record_id')->sort()->values()->all();
        /** @var array<string, EmployeeRecord> $records */
        $records = $ids === [] ? [] : $type->model()::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();

        $created = $items->where('action', RecordAction::Create)->pluck('employee_id')->unique()->values()->all();
        $inactive = $created === [] ? [] : Employee::query()->whereIn('id', $created)->get(['id', 'file_status'])
            ->reject(fn (Employee $e) => $e->isActive())->pluck('id')->all();

        $stale = [];

        foreach ($items as $item) {
            if ($item->action === RecordAction::Create) {
                if (in_array($item->employee_id, $inactive, true)) {
                    $stale[] = $item->position;
                }

                continue;
            }

            $record = $records[$item->record_id] ?? null;

            if ($record === null || $record->isDeleted()) {
                $stale[] = $item->position;

                continue;
            }

            $current = RecordValues::of($record);

            foreach ($item->old_values as $key => $value) {
                // Both in the normal form: JSON gives 20 back for 20.0.
                if (($current[$key] ?? null) !== RecordValues::normal(RecordCatalogue::field($type, $key), $value)) {
                    $stale[] = $item->position;

                    break;
                }
            }
        }

        if ($stale !== []) {
            throw ValidationException::withMessages(['change_set' => __('records.change_stale', ['positions' => implode('، ', array_unique($stale))])]);
        }

        $now = now();

        foreach ($items as $item) {
            if ($item->action === RecordAction::Delete && $type === RecordType::Debts && SubmitRecordChanges::hasPayments($item->record_id)) {
                throw ValidationException::withMessages(['change_set' => __('records.debt_has_payments')]);
            }
        }

        foreach ($items as $item) {
            match ($item->action) {
                RecordAction::Create => $this->create($type, $set, $item->record_id, $item->employee_id, $item->new_values, $reviewer),
                RecordAction::Update => $this->update($type, $records[$item->record_id], $item->new_values, $item->position, $set->items_count),
                RecordAction::Delete => $records[$item->record_id]->forceFill(['deleted_at' => $now, 'deleted_by' => $set->submitted_by])->save(),
            };
        }

        // Approved movements due today or earlier update their files now (D-048), oldest first.
        if ($type === RecordType::Movements) {
            $created = $items->where('action', RecordAction::Create)->pluck('record_id')->all();

            EmployeeMovement::query()->whereIn('id', $created)->orderBy('occurred_on')->orderBy('id')->get()
                ->each(fn (EmployeeMovement $movement) => $this->movements->handle($movement, $reviewer));
        }

        $set->forceFill([
            'status' => ChangeSetStatus::Approved,
            'self_approved' => $selfApproved,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => $now,
            'review_note' => $note,
        ])->save();
    }

    /** @param array<string, mixed> $values */
    private function create(RecordType $type, RecordChangeSet $set, string $id, string $employeeId, array $values, StaffUser $reviewer): void
    {
        $record = new ($type->model());
        $record->forceFill([
            ...$values,
            'id' => $id,
            'employee_id' => $employeeId,
            'created_by' => $set->submitted_by,
            'approved_by' => $reviewer->getKey(),
        ]);

        try {
            DB::transaction(fn () => $record->save());
        } catch (QueryException $e) {
            // Two months of attendance racing: the unique index decides; say it in Arabic.
            if (($e->errorInfo[0] ?? null) === '23505' && str_contains($e->getMessage(), 'employee_attendance_one_per_month')) {
                throw ValidationException::withMessages(['change_set' => __('records.attendance_exists', ['month' => $values['month'].'/'.$values['year']])]);
            }

            // Payments approved together, or after another, paying more than is left; or a removed debt.
            if (($e->errorInfo[0] ?? null) === '23514' && $type === RecordType::Payments) {
                throw ValidationException::withMessages(['change_set' => __(str_contains($e->getMessage(), 'not_overpaid') ? 'records.payments_over_debt' : 'records.debt_unknown', ['remaining' => ''])]);
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $values */
    private function update(RecordType $type, EmployeeRecord $record, array $values, int $position, int $count): void
    {
        // Each change was checked against the record as it was sent; checked again on the record as it is now.
        RecordValues::ensureConsistent($type, array_merge(RecordValues::of($record), $values), $count === 1 ? 'values' : 'changes.'.($position - 1).'.values', (float) $record->getAttribute('payments_total'));

        $record->forceFill($values)->save();
    }
}
