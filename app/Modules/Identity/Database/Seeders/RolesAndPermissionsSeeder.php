<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The permission catalogue of the whole system (D-019) and the roles' defaults. The catalogue is upserted
 * every run; a role gets its defaults only when it is first created, so the administrator's later changes
 * survive every deployment (D-015).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** slug => [label, read_only] */
    public const PERMISSIONS = [
        // Employees (Step 3)
        'employees.view' => ['عرض ملفات الموظفين', true],
        'employees.manage' => ['إضافة الموظفين وتحديث بياناتهم ودورات التحديث', false],
        // The employees' records: movements, monthly attendance, conduct, debts (Step 4)
        'records.view' => ['عرض سجلات الحركات والدوام والسلوك والديون', true],
        'records.manage' => ['إدخال سجلات الحركات والدوام والسلوك والديون وتصحيحها', false],
        // Documents (Step 5)
        'documents.view' => ['عرض وثائق الموظفين وتنزيلها', true],
        'documents.manage' => ['رفع وثائق الموظفين وأرشفتها', false],
        // Settings: the lists, the organisational structure, holidays, work settings (Step 2)
        'settings.manage' => ['إدارة القوائم والهيكلية التنظيمية والعطل والإعدادات', false],
        // Dashboards, forms, exports (Step 6)
        'reports.view' => ['عرض لوحات القيادة والتقارير وتصديرها', true],
        // Excel import of existing data (Step 7)
        'imports.manage' => ['استيراد بيانات الموظفين من ملفات Excel', false],
        // Audit and administration
        'audit.view' => ['عرض سجل التدقيق كاملًا', true],
        'audit.view_related' => ['عرض سجل العمليات (دون الأحداث الأمنية)', true],
        'users.manage' => ['إدارة المستخدمين والأدوار والصلاحيات', false],
        'devices.approve' => ['اعتماد أجهزة الدخول وإلغاؤها', false],
    ];

    /** Defaults when a role is created. The administrator's role stores none: it holds every permission. */
    public const ROLES = [
        StaffRole::DataEntry->value => [
            'employees.view', 'employees.manage',
            'records.view', 'records.manage',
            'documents.view', 'documents.manage',
            'imports.manage',
            'audit.view_related',
        ],
        StaffRole::GeneralManager->value => [
            'employees.view', 'records.view', 'documents.view', 'reports.view', 'audit.view',
        ],
        StaffRole::SystemAdmin->value => [],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $slug => [$label, $readOnly]) {
            Permission::query()->updateOrCreate(['slug' => $slug], ['label_ar' => $label, 'read_only' => $readOnly]);
        }

        foreach (self::ROLES as $slug => $defaults) {
            $role = Role::query()->firstOrNew(['slug' => $slug]);
            $role->label_ar = StaffRole::from($slug)->label();
            $isNew = ! $role->exists;
            $role->save();

            if ($isNew && $defaults !== []) {
                $role->permissions()->attach(Permission::query()->whereIn('slug', $defaults)->pluck('id'));
            }
        }
    }
}
