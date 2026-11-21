<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\AccessInvariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The administrator edits what a role holds (D-015). The administrator's own role holds everything and
 * is not edited; the general manager's takes read-only permissions only.
 */
final class ManageRolePermissions
{
    /**
     * @param  list<string>  $slugs
     * @return list<string> the permissions added now
     */
    public function add(Role $role, array $slugs, StaffUser $actor): array
    {
        $this->refuseAdministrator($role);

        return DB::transaction(function () use ($role, $slugs, $actor) {
            $permissions = Permission::query()->whereIn('slug', $slugs)->get();

            if ($role->isReadOnly()) {
                $notReadOnly = $permissions->where('read_only', false)->pluck('slug')->values()->all();

                if ($notReadOnly !== []) {
                    throw ValidationException::withMessages([
                        'permissions' => __('identity.read_only_role', ['permissions' => implode('، ', $notReadOnly)]),
                    ]);
                }
            }

            $held = $role->permissions()->pluck('permissions.id')->all();
            $new = $permissions->whereNotIn('id', $held);

            if ($new->isNotEmpty()) {
                $role->permissions()->attach($new->pluck('id')->all());
                $role->touch();

                AuditTrail::record('access.role_permissions_added', $actor, [
                    'role_id' => $role->getKey(),
                    'role' => $role->slug,
                    'permissions' => $new->pluck('slug')->values()->all(),
                ]);
            }

            $role->unsetRelation('permissions');

            return $new->pluck('slug')->values()->all();
        });
    }

    public function remove(Role $role, Permission $permission, StaffUser $actor): void
    {
        $this->refuseAdministrator($role);

        DB::transaction(function () use ($role, $permission, $actor) {
            $detached = $role->permissions()->detach($permission->getKey());

            if ($detached === 0) {
                throw new NotFoundHttpException;
            }

            $role->touch();
            AccessInvariants::ensureSomeoneManagesUsers('permission');

            AuditTrail::record('access.role_permission_removed', $actor, [
                'role_id' => $role->getKey(),
                'role' => $role->slug,
                'permission' => $permission->slug,
            ]);
        });

        $role->unsetRelation('permissions');
    }

    private function refuseAdministrator(Role $role): void
    {
        if ($role->isAdministrator()) {
            throw ValidationException::withMessages(['role' => __('identity.admin_role_fixed')]);
        }
    }
}
