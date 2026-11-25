<?php

namespace App\Modules\Records\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Enums\RecordAction;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Models\EmployeeRecord;
use App\Modules\Records\Models\RecordChangeItem;
use App\Modules\Records\Models\RecordChangeSet;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordChange;
use App\Modules\Records\Support\RecordValues;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every record entry goes through here (D-041), as the file's do (D-033): the values are checked, only real
 * changes are kept with the approved values beside them, and the set waits for a reviewer, unless its author
 * reviews, in which case it is applied at once and logged (D-023). A batch is checked whole: every error of
 * every change is reported together, and nothing is kept unless all are right.
 */
final class SubmitRecordChanges
{
    public function __construct(private readonly ApplyRecordChangeSet $apply) {}

    /** @param list<RecordChange> $changes */
    public function handle(RecordType $type, array $changes, ?string $note, StaffUser $author): RecordChangeSet
    {
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => __('records.nothing_to_change')]);
        }

        if (count($changes) > config('hr.records_batch_limit')) {
            throw ValidationException::withMessages(['changes' => __('records.batch_too_large', ['max' => config('hr.records_batch_limit')])]);
        }

        foreach ($changes as $change) {
            if (! $author->hasPermission($change->action->permission())) {
                throw new AuthorizationException(__('records.not_allowed', ['action' => $change->action->label()]));
            }
        }

        return DB::transaction(function () use ($type, $changes, $note, $author) {
            $records = $this->records($type, $changes);
            $employees = $this->employees($changes, $records);
            $items = [];
            $errors = [];

            foreach ($changes as $position => $change) {
                try {
                    $items[] = $this->item($type, $change, $position + 1, $records, $employees);
                } catch (ValidationException $e) {
                    $errors += $e->errors();
                }
            }

            $errors += $this->duplicates($type, $changes, $items);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return $this->record($type, $items, $note, $author);
        });
    }

    /**
     * The approved records the changes name, of this kind only.
     *
     * @param  list<RecordChange>  $changes
     * @return array<string, EmployeeRecord>
     */
    private function records(RecordType $type, array $changes): array
    {
        $ids = array_values(array_filter(array_map(fn (RecordChange $c) => $c->recordId, $changes), fn ($id) => $id !== null && Str::isUuid($id)));

        return $ids === [] ? [] : $type->model()::query()->whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * @param  list<RecordChange>  $changes
     * @param  array<string, EmployeeRecord>  $records
     * @return array<string, Employee>
     */
    private function employees(array $changes, array $records): array
    {
        $ids = array_filter(array_map(fn (RecordChange $c) => $c->employeeId, $changes), fn ($id) => $id !== null && Str::isUuid($id));
        $ids = array_values(array_unique([...$ids, ...array_map(fn (EmployeeRecord $r) => $r->employee_id, $records)]));

        return $ids === [] ? [] : Employee::query()->whereIn('id', $ids)->get(['id', 'file_status'])->keyBy('id')->all();
    }

    /**
     * @param  array<string, EmployeeRecord>  $records
     * @param  array<string, Employee>  $employees
     * @return array<string, mixed> the item's columns
     */
    private function item(RecordType $type, RecordChange $change, int $position, array $records, array $employees): array
    {
        $at = $change->prefix;
        $base = $this->base($change);
        $employeeKey = $at === 'values' ? 'employee_id' : $base.'.employee_id';

        if ($change->action === RecordAction::Create) {
            $employee = $change->employeeId === null ? null : ($employees[$change->employeeId] ?? null);

            if ($employee === null) {
                throw ValidationException::withMessages([$employeeKey => __('records.employee_unknown')]);
            }

            if (! $employee->isActive()) {
                throw ValidationException::withMessages([$employeeKey => __('records.employee_not_active', ['status' => $employee->file_status->label()])]);
            }

            $missing = [];

            foreach (RecordCatalogue::fields($type) as $key => $field) {
                if (($field['required'] ?? false) && ! array_key_exists($key, $change->values)) {
                    $missing[$at.'.'.$key] = __('validation.required', ['attribute' => $field['label']]);
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            $values = RecordValues::validate($type, $change->values, $at);
            RecordValues::ensureConsistent($type, $values, $at);

            return [
                'position' => $position,
                'action' => RecordAction::Create,
                'record_id' => (string) Str::uuid7(),
                'employee_id' => $employee->getKey(),
                'old_values' => null,
                'new_values' => array_filter($values, fn ($v) => $v !== null),
            ];
        }

        $record = $change->recordId === null ? null : ($records[$change->recordId] ?? null);

        if ($record === null || $record->isDeleted()) {
            throw ValidationException::withMessages([$base => __('records.record_unknown')]);
        }

        $current = RecordValues::of($record);

        if ($change->action === RecordAction::Delete) {
            return [
                'position' => $position,
                'action' => RecordAction::Delete,
                'record_id' => $record->getKey(),
                'employee_id' => $record->employee_id,
                'old_values' => $current,
                'new_values' => null,
            ];
        }

        $values = RecordValues::validate($type, $change->values, $at);
        $new = [];
        $old = [];

        foreach ($values as $key => $value) {
            if ($current[$key] !== $value) {
                $new[$key] = $value;
                $old[$key] = $current[$key];
            }
        }

        foreach (array_keys($new) as $key) {
            if (RecordCatalogue::field($type, $key)['fixed'] ?? false) {
                throw ValidationException::withMessages([$at.'.'.$key => __('records.field_fixed', ['field' => RecordCatalogue::label($type, $key)])]);
            }
        }

        if ($new === []) {
            throw ValidationException::withMessages([$at => __('records.nothing_changed')]);
        }

        RecordValues::ensureConsistent($type, array_merge($current, $new), $at);

        return [
            'position' => $position,
            'action' => RecordAction::Update,
            'record_id' => $record->getKey(),
            'employee_id' => $record->employee_id,
            'old_values' => $old,
            'new_values' => $new,
        ];
    }

    /**
     * A record changed twice in one submission, or a month of attendance entered twice: in this batch, already
     * approved, or waiting in another submission.
     *
     * @param  list<RecordChange>  $changes
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function duplicates(RecordType $type, array $changes, array $items): array
    {
        $errors = [];
        $seen = [];

        foreach ($items as $item) {
            $change = $changes[$item['position'] - 1];

            if ($item['action'] !== RecordAction::Create && isset($seen[$item['record_id']])) {
                $errors[$this->base($change)] = __('records.record_twice');
            }

            $seen[$item['record_id']] = true;
        }

        if ($type !== RecordType::Attendance) {
            return $errors;
        }

        $months = [];

        foreach ($items as $item) {
            if ($item['action'] !== RecordAction::Create) {
                continue;
            }

            $change = $changes[$item['position'] - 1];
            $month = $item['employee_id'].'|'.$item['new_values']['year'].'|'.$item['new_values']['month'];
            $label = ['month' => $item['new_values']['month'].'/'.$item['new_values']['year']];

            if (isset($months[$month])) {
                $errors[$this->base($change)] = __('records.attendance_twice_in_batch', $label);

                continue;
            }

            $months[$month] = true;

            $approved = DB::table('employee_attendance')->where('employee_id', $item['employee_id'])
                ->where('year', $item['new_values']['year'])->where('month', $item['new_values']['month'])
                ->whereNull('deleted_at')->exists();

            $waiting = RecordChangeItem::query()
                ->where('employee_id', $item['employee_id'])->where('action', RecordAction::Create)
                ->whereRaw("new_values->>'year' = ?", [(string) $item['new_values']['year']])
                ->whereRaw("new_values->>'month' = ?", [(string) $item['new_values']['month']])
                ->whereHas('changeSet', fn ($q) => $q->where('record_type', RecordType::Attendance)->where('status', ChangeSetStatus::Pending))
                ->exists();

            if ($approved || $waiting) {
                $errors[$this->base($change)] = __($approved ? 'records.attendance_exists' : 'records.attendance_waiting', $label);
            }
        }

        return $errors;
    }

    private function base(RecordChange $change): string
    {
        return $change->prefix === 'values' ? 'record' : substr($change->prefix, 0, -strlen('.values'));
    }

    /** @param list<array<string, mixed>> $items */
    private function record(RecordType $type, array $items, ?string $note, StaffUser $author): RecordChangeSet
    {
        $set = new RecordChangeSet;
        $set->forceFill([
            'record_type' => $type,
            'status' => ChangeSetStatus::Pending,
            'items_count' => count($items),
            'note' => $note === null || trim($note) === '' ? null : trim($note),
            'submitted_by' => $author->getKey(),
            'submitted_at' => now(),
        ])->save();

        $now = now();
        $rows = array_map(fn (array $item) => [
            'change_set_id' => $set->getKey(),
            'position' => $item['position'],
            'action' => $item['action']->value,
            'record_id' => $item['record_id'],
            'employee_id' => $item['employee_id'],
            'old_values' => $item['old_values'] === null ? null : json_encode($item['old_values'], JSON_UNESCAPED_UNICODE),
            'new_values' => $item['new_values'] === null ? null : json_encode($item['new_values'], JSON_UNESCAPED_UNICODE),
        ], $items);

        foreach (array_chunk($rows, 200) as $chunk) {
            RecordChangeItem::query()->insert($chunk);
        }

        $applied = $author->hasPermission('entries.review');
        $actions = array_count_values(array_map(fn ($i) => $i['action']->value, $items));

        AuditTrail::record('records.change_submitted', $author, [
            'record_type' => $type->value,
            'change_set_id' => $set->getKey(),
            'actions' => $actions,
            'employee_ids' => array_values(array_unique(array_column($items, 'employee_id'))),
            'applied_at_once' => $applied,
        ]);

        if ($applied) {
            $this->apply->handle($set, $author, true);

            AuditTrail::record('records.change_approved', $author, [
                'record_type' => $type->value,
                'change_set_id' => $set->getKey(),
                'self_approved' => true,
            ]);
        }

        return $set->refresh();
    }
}
