<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\EmployeeChangeSet;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who applies at once and who approves (D-023, D-034):
 * - an author who reviews (`entries.review`) applies their own change at once, logged as self-approved;
 * - a change to a field that identifies the person needs `identity.review` (the administrator by default),
 *   from the author to apply at once, or from the reviewer to approve;
 * - nobody approves their own pending change.
 */
final class ReviewRights
{
    public static function appliesAtOnce(StaffUser $author, bool $touchesIdentity): bool
    {
        return $author->hasPermission('entries.review') && (! $touchesIdentity || $author->hasPermission('identity.review'));
    }

    public static function ensureCanDecide(StaffUser $reviewer, EmployeeChangeSet $set): void
    {
        if ($set->submitted_by === $reviewer->getKey()) {
            throw new AuthorizationException(__('employees.own_change'));
        }

        if ($set->needs_identity_review && ! $reviewer->hasPermission('identity.review')) {
            throw new AuthorizationException(__('employees.identity_review_needed'));
        }
    }
}
