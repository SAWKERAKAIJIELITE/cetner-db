<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Validation\ValidationException;

/**
 * Some active staff user must always be able to manage users: a change that would leave nobody is
 * refused and rolled back (D-015). Call it inside the change's transaction, after the change.
 */
final class AccessInvariants
{
    public const MANAGE_USERS = 'users.manage';

    public static function ensureSomeoneManagesUsers(string $field = 'role'): void
    {
        $someone = StaffUser::query()
            ->where('is_active', true)
            ->whereNotNull('role_id')
            ->with('role.permissions')
            ->get()
            ->contains(fn (StaffUser $user) => $user->hasPermission(self::MANAGE_USERS));

        if (! $someone) {
            throw ValidationException::withMessages([$field => __('identity.last_user_manager')]);
        }
    }
}
