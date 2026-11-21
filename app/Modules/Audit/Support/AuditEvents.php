<?php

namespace App\Modules\Audit\Support;

/**
 * The Arabic label of every event the code records. AuditEventApiTest fails when an event is recorded
 * without a label here, or a label is left for an event nothing records.
 */
final class AuditEvents
{
    public const LABELS = [
        'auth.login' => 'تسجيل دخول',
        'auth.logout' => 'تسجيل خروج',
        'device.approved' => 'اعتماد جهاز',
        'device.revoked' => 'إلغاء جهاز',
        'access.user_created' => 'إضافة مستخدم',
        'access.role_changed' => 'تغيير دور مستخدم',
        'access.role_permissions_added' => 'إضافة صلاحيات إلى دور',
        'access.role_permission_removed' => 'إزالة صلاحية من دور',
        'access.user_deactivated' => 'إيقاف مستخدم',
        'access.user_activated' => 'إعادة تفعيل مستخدم',
        'access.password_reset' => 'تعيين كلمة مرور جديدة لمستخدم',
    ];

    public static function label(string $event): string
    {
        return self::LABELS[$event] ?? $event;
    }
}
