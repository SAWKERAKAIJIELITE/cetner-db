<?php

namespace App\Modules\Employees\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\IdentityLock;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The administrator confirms a file's identity and locks it (D-034): for a file whose addition the supervisor
 * approved, or one entered before the lock existed. Never undone.
 */
final class LockEmployeeIdentity
{
    public function handle(Employee $employee, StaffUser $actor): Employee
    {
        return DB::transaction(function () use ($employee, $actor) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

            if (! $employee->isActive()) {
                throw ValidationException::withMessages(['employee' => __('employees.file_not_active', ['status' => $employee->file_status->label()])]);
            }

            if (IdentityLock::isLocked($employee)) {
                throw ValidationException::withMessages(['employee' => __('employees.identity_already_locked')]);
            }

            $missing = array_filter(IdentityLock::REQUIRED, fn (string $key) => $employee->getAttribute($key) === null);

            if ($missing !== []) {
                throw ValidationException::withMessages(['employee' => __('employees.identity_lock_needs_names')]);
            }

            $waiting = $employee->changeSets()->where('status', ChangeSetStatus::Pending)->where('needs_identity_review', true)->exists();

            if ($waiting) {
                throw ValidationException::withMessages(['employee' => __('employees.identity_lock_pending_changes')]);
            }

            IdentityLock::lock($employee, $actor);
            $employee->save();

            AuditTrail::record('employees.identity_locked', $actor, [
                'employee_id' => $employee->getKey(),
                'fields' => array_values(array_filter(FieldCatalogue::identityKeys(), fn ($k) => $employee->getAttribute($k) !== null)),
            ]);

            return $employee;
        });
    }
}
