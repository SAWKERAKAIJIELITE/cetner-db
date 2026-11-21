<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A staff user's one role, deactivation and reactivation, and a new password for a forgotten one (D-015).
 * Each change keeps someone able to manage users, or is rolled back.
 */
final class ManageStaffAccess
{
    public function setRole(StaffUser $user, Role $role, StaffUser $actor): StaffUser
    {
        self::guardAdministratorRole($role, $actor);

        return DB::transaction(function () use ($user, $role, $actor) {
            $user = StaffUser::query()->with('role')->lockForUpdate()->findOrFail($user->getKey());
            $from = $user->role?->slug;

            if ($user->role_id !== $role->getKey()) {
                $user->forceFill([
                    'role_id' => $role->getKey(),
                    'role_assigned_by' => $actor->getKey(),
                    'role_assigned_at' => now(),
                ])->save();

                AccessInvariants::ensureSomeoneManagesUsers('role');

                AuditTrail::record('access.role_changed', $actor, [
                    'staff_user_id' => $user->getKey(),
                    'from' => $from,
                    'to' => $role->slug,
                ]);
            }

            $user->forgetPermissions();

            return $user->load('role');
        });
    }

    public function deactivate(StaffUser $user, string $reason, StaffUser $actor): StaffUser
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => __('identity.cannot_deactivate_self')]);
        }

        return DB::transaction(function () use ($user, $reason, $actor) {
            $user = StaffUser::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $user->is_active) {
                throw ValidationException::withMessages(['user' => __('identity.already_inactive')]);
            }

            $user->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $actor->getKey(),
                'deactivation_reason' => $reason,
            ])->save();

            $tokens = $user->tokens()->delete();
            AccessInvariants::ensureSomeoneManagesUsers('user');

            AuditTrail::record('access.user_deactivated', $actor, [
                'staff_user_id' => $user->getKey(),
                'reason' => $reason,
                'tokens_deleted' => $tokens,
            ]);

            return $user;
        });
    }

    public function activate(StaffUser $user, StaffUser $actor): StaffUser
    {
        return DB::transaction(function () use ($user, $actor) {
            $user = StaffUser::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($user->is_active) {
                throw ValidationException::withMessages(['user' => __('identity.already_active')]);
            }

            $user->forceFill([
                'is_active' => true,
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ])->save();

            AuditTrail::record('access.user_activated', $actor, ['staff_user_id' => $user->getKey()]);

            return $user;
        });
    }

    /** A forgotten password: set by an administrator; every session of the user ends; the device stays. */
    public function resetPassword(StaffUser $user, string $password, StaffUser $actor): StaffUser
    {
        return DB::transaction(function () use ($user, $password, $actor) {
            $user = StaffUser::query()->lockForUpdate()->findOrFail($user->getKey());
            $user->forceFill(['password' => $password])->save();
            $tokens = $user->tokens()->delete();

            AuditTrail::record('access.password_reset', $actor, [
                'staff_user_id' => $user->getKey(),
                'tokens_deleted' => $tokens,
            ]);

            return $user;
        });
    }

    /** Only the system administrator gives the system administrator's role (no escalation, D-015). */
    public static function guardAdministratorRole(Role $role, ?StaffUser $actor): void
    {
        if ($actor !== null && $role->isAdministrator() && ! ($actor->role?->isAdministrator() ?? false)) {
            throw new AuthorizationException(__('identity.admin_role_by_admin_only'));
        }
    }
}
