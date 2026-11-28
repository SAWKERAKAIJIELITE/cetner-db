<?php

namespace App\Modules\Employees\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\ChangeSetKind;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeItem;
use App\Modules\Employees\Models\EmployeeChangeSet;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\FieldDefinitions;
use App\Modules\Employees\Support\FieldValues;
use App\Modules\Employees\Support\IdentityLock;
use App\Modules\Employees\Support\ReviewRights;
use App\Modules\Identity\Models\StaffUser;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every entry goes through here (D-033): a new employee, changes to a file, or the confirmation of a cycle.
 * The values are checked, only real changes are kept (with the approved value beside each), and the set
 * waits for a reviewer, unless its author reviews, in which case it is applied at once and logged (D-023).
 * The identity lock (D-034) refuses any change to a locked identity value and sends any other identity
 * change to the administrator.
 */
final class SubmitEmployeeChanges
{
    /**
     * A new file needs «الرقم الذاتي», the workbook's key (every record names the employee by it), «الرقم الوطني»
     * (owner, 25 Nov), and the three names of «الاسم الثلاثي»; everything else may come later (D-032).
     */
    public const REQUIRED_TO_CREATE = ['personal_number', 'national_number', 'first_name', 'father_name', 'family_name'];

    public function __construct(private readonly ApplyChangeSet $apply) {}

    /** @param array<string, mixed> $values */
    public function create(array $values, ?string $note, StaffUser $author): EmployeeChangeSet
    {
        $values = FieldValues::validate($values);
        $missing = array_filter(self::REQUIRED_TO_CREATE, fn (string $key) => ($values[$key] ?? null) === null);

        if ($missing !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(fn ($k) => 'values.'.$k, $missing),
                array_map(fn ($k) => __('validation.required', ['attribute' => FieldCatalogue::get($k)['label']]), $missing),
            ));
        }

        return DB::transaction(function () use ($values, $note, $author) {
            $employee = new Employee;
            $employee->forceFill(['created_by' => $author->getKey()])->save();

            $items = array_filter($values, fn ($value) => $value !== null);
            $this->ensureUnique($items, $employee);

            return $this->record($employee, ChangeSetKind::Create, null, null, $items, [], $note, $author, false);
        });
    }

    /** @param array<string, mixed> $values */
    public function update(Employee $employee, array $values, ?string $note, StaffUser $author): EmployeeChangeSet
    {
        if ($values === []) {
            throw ValidationException::withMessages(['values' => __('employees.nothing_to_change')]);
        }

        $values = FieldValues::validate($values);

        return DB::transaction(function () use ($employee, $values, $note, $author) {
            $employee = $this->lockActive($employee);
            [$items, $old] = $this->changes($employee, $values);

            if ($items === []) {
                throw ValidationException::withMessages(['values' => __('employees.nothing_changed')]);
            }

            $identity = $this->guardIdentity($employee, $items);
            $this->ensureContractOrder($employee, $items);
            $this->ensureUnique($items, $employee);

            return $this->record($employee, ChangeSetKind::Update, null, null, $items, $old, $note, $author, $identity);
        });
    }

    /**
     * What an approved movement makes of the file (D-048): the fields that differ, as a change of the kind
     * «تطبيق حركة», applied at once (the movement's reviewer approved it). None when nothing differs.
     *
     * @param  array<string, mixed>  $values
     */
    public function fromMovement(Employee $employee, array $values, string $note, StaffUser $actor): ?EmployeeChangeSet
    {
        $values = FieldValues::validate($values);
        $employee = $this->lockActive($employee);
        [$items, $old] = $this->changes($employee, $values);

        return $items === [] ? null : $this->record($employee, ChangeSetKind::Movement, null, null, $items, $old, $note, $actor, false);
    }

    /**
     * A row of an approved import (D-053): a new file, or the fields of a stored one that differ, applied at once by
     * the import's reviewer and marked with the import. The rules are a submission's: the values, the identity lock,
     * the contract's order, unique numbers. An imported identity is not locked: the administrator locks it after
     * checking it (D-034). Null when nothing differs.
     *
     * @param  array<string, mixed>  $values
     */
    public function fromImport(?Employee $employee, array $values, string $note, StaffUser $uploader, StaffUser $approver, string $importId): ?EmployeeChangeSet
    {
        $values = FieldValues::validate($values);

        if ($employee === null) {
            $missing = array_filter(self::REQUIRED_TO_CREATE, fn (string $key) => ($values[$key] ?? null) === null);

            if ($missing !== []) {
                throw ValidationException::withMessages(['values' => __('validation.required', ['attribute' => FieldCatalogue::get(reset($missing))['label']])]);
            }

            $employee = new Employee;
            $employee->forceFill(['created_by' => $uploader->getKey()])->save();
            $items = array_filter($values, fn ($value) => $value !== null);
            $this->ensureContractOrder($employee, $items);
            $this->ensureUnique($items, $employee);

            return $this->record($employee, ChangeSetKind::Create, null, null, $items, [], $note, $uploader, false, $approver, $importId);
        }

        $employee = $this->lockActive($employee);
        [$items, $old] = $this->changes($employee, $values);

        if ($items === []) {
            return null;
        }

        IdentityLock::ensureChangeable($employee, $items);
        $this->ensureContractOrder($employee, $items);
        $this->ensureUnique($items, $employee);

        return $this->record($employee, ChangeSetKind::Update, null, null, $items, $old, $note, $uploader, false, $approver, $importId);
    }

    /**
     * Confirm a cycle for the current period, with the changes found (or none). Only the cycle's fields.
     *
     * @param  array<string, mixed>  $values
     */
    public function review(Employee $employee, Cycle $cycle, array $values, ?string $note, StaffUser $author): EmployeeChangeSet
    {
        // Unknown keys are FieldValues' to refuse; known ones of another cycle are refused here.
        $outside = array_values(array_intersect(array_diff(array_keys($values), FieldDefinitions::ofCycle($cycle)), FieldCatalogue::keys()));

        if ($outside !== []) {
            throw ValidationException::withMessages(['values' => __('employees.not_in_cycle', [
                'cycle' => $cycle->label(),
                'fields' => implode('، ', array_map(fn ($k) => FieldCatalogue::get($k)['label'], $outside)),
            ])]);
        }

        $values = FieldValues::validate($values);
        $period = $cycle->periodOf(BusinessDay::today());

        return DB::transaction(function () use ($employee, $cycle, $period, $values, $note, $author) {
            $employee = $this->lockActive($employee);

            $taken = EmployeeChangeSet::query()
                ->where('employee_id', $employee->getKey())->where('kind', ChangeSetKind::Review)
                ->where('cycle', $cycle)->where('period', $period)
                ->whereIn('status', [ChangeSetStatus::Pending, ChangeSetStatus::Approved])
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['cycle' => __('employees.review_already', ['cycle' => $cycle->label(), 'period' => $period])]);
            }

            [$items, $old] = $this->changes($employee, $values);
            $identity = $this->guardIdentity($employee, $items);
            $this->ensureContractOrder($employee, $items);
            $this->ensureUnique($items, $employee);

            return $this->record($employee, ChangeSetKind::Review, $cycle, $period, $items, $old, $note, $author, $identity);
        });
    }

    private function lockActive(Employee $employee): Employee
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

        if (! $employee->isActive()) {
            throw ValidationException::withMessages(['employee' => __('employees.file_not_active', ['status' => $employee->file_status->label()])]);
        }

        return $employee;
    }

    /**
     * Only the values that differ from the approved ones.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} new values, approved values
     */
    private function changes(Employee $employee, array $values): array
    {
        $items = [];
        $old = [];

        foreach ($values as $key => $value) {
            $current = FieldValues::current($employee, $key);

            if ($current !== $value) {
                $items[$key] = $value;
                $old[$key] = $current;
            }
        }

        return [$items, $old];
    }

    /**
     * The identity lock (D-034): a locked identity value is refused for everyone; any identity field makes the
     * change the administrator's to approve (`identity.review`).
     */
    private function guardIdentity(Employee $employee, array $items): bool
    {
        IdentityLock::ensureChangeable($employee, $items);

        return array_intersect(array_keys($items), FieldCatalogue::identityKeys()) !== [];
    }

    private function ensureContractOrder(Employee $employee, array $items): void
    {
        $starts = $items['contract_starts_on'] ?? FieldValues::current($employee, 'contract_starts_on');
        $ends = $items['contract_ends_on'] ?? FieldValues::current($employee, 'contract_ends_on');

        if ($starts !== null && $ends !== null && $ends < $starts && (isset($items['contract_starts_on']) || isset($items['contract_ends_on']))) {
            throw ValidationException::withMessages(['values.contract_ends_on' => __('employees.contract_ends_before_start')]);
        }
    }

    /** A national or personal number is one person's: not another file's, nor waiting in another change. */
    private function ensureUnique(array $items, Employee $employee): void
    {
        foreach (['national_number', 'personal_number'] as $field) {
            if (! isset($items[$field])) {
                continue;
            }

            $value = $items[$field];
            $inFile = Employee::query()->where($field, $value)->whereKeyNot($employee->getKey())->exists();
            $waiting = EmployeeChangeItem::query()
                ->where('field', $field)
                ->whereRaw('new_value = to_jsonb(?::text)', [$value])
                ->whereHas('changeSet', fn ($q) => $q->where('status', ChangeSetStatus::Pending)->where('employee_id', '<>', $employee->getKey()))
                ->exists();

            if ($inFile || $waiting) {
                throw ValidationException::withMessages(['values.'.$field => __($inFile ? 'employees.unique_taken' : 'employees.unique_waiting', [
                    'field' => FieldCatalogue::get($field)['label'],
                ])]);
            }
        }
    }

    private function record(Employee $employee, ChangeSetKind $kind, ?Cycle $cycle, ?string $period, array $items, array $old, ?string $note, StaffUser $author, bool $identity, ?StaffUser $approver = null, ?string $importId = null): EmployeeChangeSet
    {
        $set = new EmployeeChangeSet;
        $set->forceFill([
            'employee_id' => $employee->getKey(),
            'kind' => $kind,
            'cycle' => $cycle,
            'period' => $period,
            'status' => ChangeSetStatus::Pending,
            'needs_identity_review' => $identity,
            'note' => $note === null || trim($note) === '' ? null : trim($note),
            'submitted_by' => $author->getKey(),
            'submitted_at' => now(),
            'import_id' => $importId,
        ])->save();

        // One insert for the set's items (the json cast's encoding).
        EmployeeChangeItem::query()->insert(array_map(fn ($field, $value) => [
            'change_set_id' => $set->getKey(),
            'field' => $field,
            'old_value' => json_encode($old[$field] ?? null),
            'new_value' => json_encode($value),
        ], array_keys($items), $items));

        // An import's rows were approved with the import, by its reviewer.
        $applied = $kind === ChangeSetKind::Movement || $importId !== null || ReviewRights::appliesAtOnce($author, $identity);
        $approver ??= $author;

        AuditTrail::record('employees.change_submitted', $author, [
            'employee_id' => $employee->getKey(),
            'change_set_id' => $set->getKey(),
            'kind' => $kind->value,
            'cycle' => $cycle?->value,
            'period' => $period,
            'fields' => array_keys($items),
            'needs_identity_review' => $identity,
            'applied_at_once' => $applied,
            'import_id' => $importId,
        ]);

        if ($applied) {
            $self = $approver->getKey() === $author->getKey();
            $this->apply->handle($set, $employee, $approver, $self);

            AuditTrail::record('employees.change_approved', $approver, [
                'employee_id' => $employee->getKey(),
                'change_set_id' => $set->getKey(),
                'self_approved' => $self,
                'import_id' => $importId,
            ]);
        }

        return $set->refresh();
    }
}
