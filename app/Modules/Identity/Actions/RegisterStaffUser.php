<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/** A new staff account with its one role, from the API or from the shell (the first administrator). */
final class RegisterStaffUser
{
    /**
     * @param  array{username: string, first_name: string, last_name: string, personal_number: string, password: string, is_active?: bool}  $data
     */
    public function handle(array $data, Role $role, ?StaffUser $actor, string $via = 'api'): StaffUser
    {
        ManageStaffAccess::guardAdministratorRole($role, $actor);

        return DB::transaction(function () use ($data, $role, $actor, $via) {
            $user = new StaffUser([
                'username' => $data['username'],
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'personal_number' => $data['personal_number'],
                'password' => $data['password'],
            ]);

            $active = $data['is_active'] ?? true;

            $user->forceFill([
                'role_id' => $role->getKey(),
                'role_assigned_by' => $actor?->getKey(),
                'role_assigned_at' => now(),
                'is_active' => $active,
                'deactivated_at' => $active ? null : now(),
                'deactivated_by' => $active ? null : $actor?->getKey(),
                'deactivation_reason' => $active ? null : __('identity.created_inactive'),
            ])->save();

            AuditTrail::record('access.user_created', $actor, [
                'staff_user_id' => $user->getKey(),
                'username' => $user->username,
                'role' => $role->slug,
                'is_active' => $active,
                'via' => $via,
            ]);

            return $user->load('role');
        });
    }
}
