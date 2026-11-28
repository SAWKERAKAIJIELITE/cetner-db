<?php

namespace App\Modules\Employees\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\ChangeSetKind;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\FileStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeSet;
use App\Modules\Employees\Models\EmployeeReview;
use App\Modules\Employees\Support\EmployeeDerived;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\FieldValues;
use App\Modules\Employees\Support\IdentityLock;
use App\Modules\Identity\Models\StaffUser;
use App\Support\BusinessDay;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Approves a pending change set and writes its values into the file: the one place a field of `employees`
 * is written (D-033). Called inside the caller's transaction, with the set and the employee locked.
 *
 * A value that changed since the set was submitted makes the set stale: it is refused, not merged, so a
 * reviewer never approves a change against a value they did not see.
 */
final class ApplyChangeSet
{
    public function handle(EmployeeChangeSet $set, Employee $employee, StaffUser $reviewer, bool $selfApproved, ?string $note = null): void
    {
        $items = $set->items()->get();

        // Locked since it was submitted: a locked identity value is never changed (D-034).
        IdentityLock::ensureChangeable($employee, $items->pluck('new_value', 'field')->all());

        if ($set->kind !== ChangeSetKind::Create) {
            $stale = $items->filter(fn ($item) => FieldValues::current($employee, $item->field) !== $item->old_value);

            if ($stale->isNotEmpty()) {
                throw ValidationException::withMessages(['change_set' => __('employees.change_stale', [
                    'fields' => $stale->map(fn ($i) => FieldCatalogue::get($i->field)['label'])->implode('، '),
                ])]);
            }
        }

        foreach ($items as $item) {
            $employee->setAttribute($item->field, $item->new_value);
        }

        if ($set->kind === ChangeSetKind::Create) {
            $employee->file_status = FileStatus::Active;
            $employee->activated_at = now();
        }

        // The administrator approving the identity locks it; an empty identity field filled later joins the lock.
        $touchesIdentity = $items->pluck('field')->intersect(FieldCatalogue::identityKeys())->isNotEmpty();

        if (IdentityLock::isLocked($employee)) {
            IdentityLock::refreshFingerprint($employee);
        } elseif ($set->getAttribute('import_id') === null && $reviewer->hasPermission('identity.review') && ($set->kind === ChangeSetKind::Create || $touchesIdentity)) {
            // An imported identity waits for the administrator to check it (D-053).
            IdentityLock::lock($employee, $reviewer);
            $lockedNow = true;
        }

        EmployeeDerived::refresh($employee);

        try {
            $employee->save();
        } catch (QueryException $e) {
            throw $this->uniqueViolation($e) ?? $e;
        }

        $set->forceFill([
            'status' => ChangeSetStatus::Approved,
            'self_approved' => $selfApproved,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        if ($lockedNow ?? false) {
            AuditTrail::record('employees.identity_locked', $reviewer, [
                'employee_id' => $employee->getKey(),
                'change_set_id' => $set->getKey(),
            ]);
        }

        if ($set->kind === ChangeSetKind::Review) {
            $review = new EmployeeReview;
            $review->forceFill([
                'employee_id' => $employee->getKey(),
                'cycle' => $set->cycle,
                'period' => $set->period,
                'reviewed_on' => BusinessDay::today()->format('Y-m-d'),
                'change_set_id' => $set->getKey(),
                'confirmed_by' => $set->submitted_by,
                'approved_by' => $reviewer->getKey(),
            ])->save();
        }
    }

    /** Two files racing for one national or personal number: the unique index decides; say it in Arabic. */
    private function uniqueViolation(QueryException $e): ?ValidationException
    {
        if (($e->errorInfo[0] ?? null) !== '23505') {
            return null;
        }

        foreach (['national_number', 'personal_number'] as $field) {
            if (str_contains($e->getMessage(), 'employees_'.$field.'_unique')) {
                return ValidationException::withMessages(['values.'.$field => __('employees.unique_taken', ['field' => FieldCatalogue::get($field)['label']])]);
            }
        }

        return null;
    }
}
