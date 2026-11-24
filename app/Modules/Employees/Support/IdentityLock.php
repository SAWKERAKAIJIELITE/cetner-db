<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Validation\ValidationException;

/**
 * The identity lock (D-034) and its fingerprint (D-038).
 *
 * - The six identity fields of a locked file are never changed again, by anyone (the database refuses it too).
 *   A field still empty may be filled once, approved by `identity.review`; then it is locked as well.
 * - A file's identity is locked when a holder of `identity.review` (the administrator by default) approves it:
 *   by adding the file, by approving its addition or a change of its identity, or with the lock action.
 * - The fingerprint is an HMAC-SHA256 of the identity, keyed with `hr.identity_hash_key`: it proves the locked
 *   identity is untouched (`employees:verify-identity`), and cannot be reversed into the national number
 *   without the key.
 */
final class IdentityLock
{
    public const REQUIRED = ['first_name', 'father_name', 'family_name'];

    public static function isLocked(Employee $employee): bool
    {
        return $employee->identity_locked_at !== null;
    }

    /**
     * Refuse changing a locked identity value; filling an empty one is allowed.
     *
     * @param  array<string, mixed>  $items  field => new value
     */
    public static function ensureChangeable(Employee $employee, array $items): void
    {
        if (! self::isLocked($employee)) {
            return;
        }

        $locked = array_values(array_filter(
            array_intersect(array_keys($items), FieldCatalogue::identityKeys()),
            fn (string $key) => $employee->getAttribute($key) !== null,
        ));

        if ($locked !== []) {
            throw ValidationException::withMessages(['values' => __('employees.identity_locked', [
                'fields' => implode('، ', array_map(fn ($k) => FieldCatalogue::get($k)['label'], $locked)),
            ])]);
        }
    }

    /** Lock the identity, on an employee already holding its approved values (the caller saves). */
    public static function lock(Employee $employee, StaffUser $by): void
    {
        $employee->forceFill([
            'identity_locked_at' => now(),
            'identity_locked_by' => $by->getKey(),
            'identity_hash' => self::fingerprint($employee),
        ]);
    }

    /** After an empty identity field of a locked file was filled. */
    public static function refreshFingerprint(Employee $employee): void
    {
        if (self::isLocked($employee)) {
            $employee->identity_hash = self::fingerprint($employee);
        }
    }

    public static function fingerprint(Employee $employee): string
    {
        $parts = array_map(fn (string $key) => $key.'='.(string) FieldValues::current($employee, $key), FieldCatalogue::identityKeys());

        return hash_hmac('sha256', implode("\x1f", $parts), self::key());
    }

    public static function intact(Employee $employee): bool
    {
        return ! self::isLocked($employee) || hash_equals((string) $employee->identity_hash, self::fingerprint($employee));
    }

    private static function key(): string
    {
        $key = (string) config('hr.identity_hash_key');

        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        if ($key === '') {
            throw new \RuntimeException('HR_IDENTITY_HASH_KEY (or APP_KEY) is not set.');
        }

        return $key;
    }
}
