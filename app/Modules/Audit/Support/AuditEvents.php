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
        'settings.list_item_added' => 'إضافة عنصر إلى قائمة',
        'settings.list_item_updated' => 'تعديل عنصر في قائمة',
        'settings.list_item_archived' => 'أرشفة عنصر من قائمة',
        'settings.list_item_restored' => 'استعادة عنصر إلى قائمة',
        'settings.list_reordered' => 'إعادة ترتيب قائمة',
        'settings.org_unit_added' => 'إضافة جهة عمل',
        'settings.org_unit_renamed' => 'تعديل اسم جهة عمل',
        'settings.org_unit_archived' => 'أرشفة جهة عمل',
        'settings.org_unit_restored' => 'استعادة جهة عمل',
        'settings.holiday_added' => 'إضافة عطلة رسمية',
        'settings.holiday_updated' => 'تعديل عطلة رسمية',
        'settings.holiday_deleted' => 'حذف عطلة رسمية',
        'settings.work_updated' => 'تعديل إعدادات الفرع',
        'employees.change_submitted' => 'إرسال إضافة أو تعديل لملف موظف',
        'employees.change_approved' => 'اعتماد إضافة أو تعديل لملف موظف',
        'employees.change_rejected' => 'ردّ إضافة أو تعديل لملف موظف',
        'employees.change_withdrawn' => 'سحب إضافة أو تعديل لملف موظف',
        'employees.identity_locked' => 'تثبيت هوية موظف (لا تُعدّل بعدها)',
        'employees.identity_check_failed' => 'تحقق البصمة: هوية مثبتة تغيّرت خارج النظام',
        'employees.field_definition_updated' => 'تعديل تعريف حقل في دليل الحقول',
        'records.change_submitted' => 'إرسال إضافة أو تصحيح أو حذف في السجلات',
        'records.change_approved' => 'اعتماد إضافة أو تصحيح أو حذف في السجلات',
        'records.change_rejected' => 'ردّ إضافة أو تصحيح أو حذف في السجلات',
        'records.change_withdrawn' => 'سحب إضافة أو تصحيح أو حذف في السجلات',
        'documents.uploaded' => 'رفع وثيقة لملف موظف',
        'documents.approved' => 'اعتماد وثيقة لملف موظف',
        'documents.rejected' => 'ردّ وثيقة مرفوعة',
        'documents.withdrawn' => 'سحب وثيقة مرفوعة',
        'documents.removed' => 'إزالة وثيقة حالية من ملف موظف',
        'documents.downloaded' => 'تنزيل وثيقة أو عرضها',
        'reports.form_printed' => 'طباعة استمارة موظف (PDF)',
        'exports.downloaded' => 'تصدير بيانات إلى Excel',
        'imports.uploaded' => 'رفع ملف للاستيراد',
        'imports.approved' => 'اعتماد استيراد',
        'imports.applied' => 'اكتمال استيراد',
        'imports.failed' => 'فشل استيراد',
        'imports.rejected' => 'ردّ استيراد',
        'imports.cancelled' => 'إلغاء استيراد',
    ];

    public static function label(string $event): string
    {
        return self::LABELS[$event] ?? $event;
    }
}
