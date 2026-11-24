<?php

namespace App\Modules\Employees\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\ChangeSetKind;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\FileStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeSet;
use App\Modules\Employees\Support\ReviewRights;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A pending change set's outcome (D-033): approved (its values written), refused with a reason, or taken
 * back by its author. A refused or withdrawn new employee's file stays, marked, for the history.
 */
final class DecideChangeSet
{
    public function __construct(private readonly ApplyChangeSet $apply) {}

    public function approve(EmployeeChangeSet $set, StaffUser $reviewer, ?string $note): EmployeeChangeSet
    {
        return DB::transaction(function () use ($set, $reviewer, $note) {
            [$set, $employee] = $this->lockPending($set);
            ReviewRights::ensureCanDecide($reviewer, $set);

            $this->apply->handle($set, $employee, $reviewer, false, $this->clean($note));

            AuditTrail::record('employees.change_approved', $reviewer, [
                'employee_id' => $employee->getKey(),
                'change_set_id' => $set->getKey(),
                'self_approved' => false,
            ]);

            return $set->refresh();
        });
    }

    public function reject(EmployeeChangeSet $set, StaffUser $reviewer, string $note): EmployeeChangeSet
    {
        return DB::transaction(function () use ($set, $reviewer, $note) {
            [$set, $employee] = $this->lockPending($set);
            ReviewRights::ensureCanDecide($reviewer, $set);

            $set->forceFill([
                'status' => ChangeSetStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => $this->clean($note),
            ])->save();

            if ($set->kind === ChangeSetKind::Create) {
                $employee->forceFill(['file_status' => FileStatus::Rejected])->save();
            }

            AuditTrail::record('employees.change_rejected', $reviewer, [
                'employee_id' => $employee->getKey(),
                'change_set_id' => $set->getKey(),
                'reason' => $this->clean($note),
            ]);

            return $set->refresh();
        });
    }

    public function withdraw(EmployeeChangeSet $set, StaffUser $author): EmployeeChangeSet
    {
        return DB::transaction(function () use ($set, $author) {
            [$set, $employee] = $this->lockPending($set);

            if ($set->submitted_by !== $author->getKey()) {
                throw new AuthorizationException(__('employees.withdraw_own_only'));
            }

            $set->forceFill(['status' => ChangeSetStatus::Withdrawn, 'reviewed_at' => now()])->save();

            if ($set->kind === ChangeSetKind::Create) {
                $employee->forceFill(['file_status' => FileStatus::Withdrawn])->save();
            }

            AuditTrail::record('employees.change_withdrawn', $author, [
                'employee_id' => $employee->getKey(),
                'change_set_id' => $set->getKey(),
            ]);

            return $set->refresh();
        });
    }

    /** @return array{0: EmployeeChangeSet, 1: Employee} the employee first, then the set: one lock order */
    private function lockPending(EmployeeChangeSet $set): array
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($set->employee_id);
        $set = EmployeeChangeSet::query()->lockForUpdate()->findOrFail($set->getKey());

        if (! $set->isPending()) {
            throw ValidationException::withMessages(['change_set' => __('employees.not_pending', ['status' => $set->status->label()])]);
        }

        return [$set, $employee];
    }

    private function clean(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }
}
