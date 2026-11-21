<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ManageStaffAccess;
use App\Modules\Identity\Actions\RegisterStaffUser;
use App\Modules\Identity\Http\Requests\ReasonRequest;
use App\Modules\Identity\Http\Requests\RegisterUserRequest;
use App\Modules\Identity\Http\Requests\ResetUserPasswordRequest;
use App\Modules\Identity\Http\Requests\SetUserRoleRequest;
use App\Modules\Identity\Http\Requests\UserIndexRequest;
use App\Modules\Identity\Http\Resources\StaffUserResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use App\Support\ArabicText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffUserController extends Controller
{
    /** Staff users, by username; filter by `q`, `role`, `is_active`. */
    public function index(UserIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $users = StaffUser::query()
            ->with('role')
            ->when($filters['q'] ?? null, function ($query, string $q) {
                $like = '%'.addcslashes(ArabicText::digitsToAscii(trim($q)), '%_\\').'%';
                $query->where(fn ($w) => $w->where('username', 'ilike', $like)
                    ->orWhere('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('personal_number', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($q, $slug) => $q->whereHas('role', fn ($r) => $r->where('slug', $slug)))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('username')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return StaffUserResource::collection($users);
    }

    /** One staff user with their effective permissions. */
    public function show(StaffUser $user): StaffUserResource
    {
        return (new StaffUserResource($user->load('role')))->withPermissions();
    }

    /** Add a staff user with their role. */
    public function store(RegisterUserRequest $request, RegisterStaffUser $action): JsonResponse
    {
        $data = $request->validated();
        $role = Role::query()->where('slug', $data['role'])->firstOrFail();
        $user = $action->handle($data, $role, $request->user());

        return (new StaffUserResource($user))->withPermissions()->response()->setStatusCode(201);
    }

    /** Replace the user's one role. */
    public function setRole(SetUserRoleRequest $request, StaffUser $user, ManageStaffAccess $action): StaffUserResource
    {
        $role = Role::query()->where('slug', $request->validated('role'))->firstOrFail();

        return (new StaffUserResource($action->setRole($user, $role, $request->user())))->withPermissions();
    }

    /** Deactivate a user (not yourself): every session ends. */
    public function deactivate(ReasonRequest $request, StaffUser $user, ManageStaffAccess $action): StaffUserResource
    {
        return new StaffUserResource($action->deactivate($user, $request->validated('reason'), $request->user())->load('role'));
    }

    /** Reactivate a user. */
    public function activate(Request $request, StaffUser $user, ManageStaffAccess $action): StaffUserResource
    {
        return new StaffUserResource($action->activate($user, $request->user())->load('role'));
    }

    /** Set a new password for a forgotten one: the user is signed out everywhere; the device stays approved. */
    public function resetPassword(ResetUserPasswordRequest $request, StaffUser $user, ManageStaffAccess $action): StaffUserResource
    {
        return new StaffUserResource($action->resetPassword($user, $request->validated('password'), $request->user())->load('role'));
    }
}
