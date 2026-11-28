<?php

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\Log;

/**
 * The single call for "who did what" (D-017). It writes a row of audit_events in the caller's
 * transaction, so a rolled-back action leaves none, and a log line.
 */
final class AuditTrail
{
    public const SECURITY_PREFIXES = ['access.', 'device.', 'auth.'];

    /** Events of other areas that are about access: reading an employee's documents (D-049). */
    public const SECURITY_EVENTS = ['documents.downloaded'];

    public static function record(string $event, ?StaffUser $actor, array $context = []): AuditEvent
    {
        $category = self::categoryOf($event);
        $ip = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()?->ip();

        $row = new AuditEvent;
        $row->forceFill([
            'event' => $event,
            'category' => $category,
            'actor_id' => $actor?->getKey(),
            'context' => $context,
            'ip' => $ip,
            'related_ids' => self::toPostgresArray(self::relatedIds($context)),
        ])->save();

        Log::info('audit.'.$event, ['actor_id' => $actor?->getKey(), 'context' => $context]);

        return $row;
    }

    /** @param list<string> $values */
    private static function toPostgresArray(array $values): string
    {
        $quoted = array_map(fn (string $v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values);

        return '{'.implode(',', $quoted).'}';
    }

    public static function categoryOf(string $event): string
    {
        if (in_array($event, self::SECURITY_EVENTS, true)) {
            return 'security';
        }

        foreach (self::SECURITY_PREFIXES as $prefix) {
            if (str_starts_with($event, $prefix)) {
                return 'security';
            }
        }

        return 'operations';
    }

    /**
     * The record ids an event is about: the context's `id`, `*_id` and `*_ids` values, at any depth.
     *
     * @return list<string>
     */
    public static function relatedIds(array $context): array
    {
        $ids = [];

        $walk = function (array $values) use (&$walk, &$ids): void {
            foreach ($values as $key => $value) {
                $isIdKey = is_string($key) && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_ids'));

                if ($isIdKey && (is_string($value) || is_int($value))) {
                    $ids[] = (string) $value;
                } elseif ($isIdKey && is_array($value)) {
                    foreach ($value as $item) {
                        if (is_string($item) || is_int($item)) {
                            $ids[] = (string) $item;
                        }
                    }
                } elseif (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($context);

        return array_values(array_unique($ids));
    }
}
