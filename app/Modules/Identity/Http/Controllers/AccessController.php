<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ManageRolePermissions;
use App\Modules\Identity\Http\Requests\AddRolePermissionsRequest;
use App\Modules\Identity\Http\Resources\PermissionResource;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccessController extends Controller
{
    /** The permission catalogue. */
    public function permissions(): AnonymousResourceCollection
    {
        return PermissionResource::collection(Permission::query()->orderBy('slug')->get());
    }

    /** The roles with their permissions and how many users hold each. */
    public function roles(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->with('permissions')->withCount('users')->orderBy('id')->get());
    }

    /** Add permissions to a role (not the administrator's; read-only ones only to the general manager's). */
    public function addPermissions(AddRolePermissionsRequest $request, Role $role, ManageRolePermissions $action): RoleResource
    {
        $action->add($role, $request->validated('permissions'), $request->user());

        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    /** Remove one permission from a role (404 when the role does not hold it). */
    public function removePermission(Request $request, Role $role, Permission $permission, ManageRolePermissions $action): RoleResource
    {
        $action->remove($role, $permission, $request->user());

        return new RoleResource($role->load('permissions')->loadCount('users'));
    }
}
