<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `employees.manage` becomes `employees.create` + `employees.update`; `records.manage` becomes
 * `records.create` + `records.update` + `records.delete`; the supervisor's role gets the two update
 * permissions (D-023). For a database seeded before this change: a role that held a "manage" permission
 * holds its parts afterwards. On a new database there is nothing to do (the seeder creates the catalogue).
 */
return new class extends Migration
{
    private const SPLITS = [
        'employees.manage' => [
            'employees.create' => 'إضافة موظفين جدد',
            'employees.update' => 'تحديث بيانات الموظفين ودورات التحديث',
        ],
        'records.manage' => [
            'records.create' => 'إدخال سجلات الحركات والدوام والسلوك والديون',
            'records.update' => 'تصحيح سجلات الحركات والدوام والسلوك والديون',
            'records.delete' => 'حذف سجل أُدخل خطأً',
        ],
    ];

    public function up(): void
    {
        $changed = false;

        foreach (self::SPLITS as $old => $parts) {
            $oldId = DB::table('permissions')->where('slug', $old)->value('id');

            if ($oldId === null) {
                continue;
            }

            $changed = true;
            $roleIds = DB::table('permission_role')->where('permission_id', $oldId)->pluck('role_id');

            foreach ($parts as $slug => $label) {
                DB::table('permissions')->insertOrIgnore([
                    'slug' => $slug, 'label_ar' => $label, 'read_only' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $newId = DB::table('permissions')->where('slug', $slug)->value('id');

                foreach ($roleIds as $roleId) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $newId]);
                }
            }

            DB::table('permission_role')->where('permission_id', $oldId)->delete();
            DB::table('permissions')->where('id', $oldId)->delete();
        }

        $supervisor = DB::table('roles')->where('slug', 'supervisor')->value('id');

        if ($changed && $supervisor !== null) {
            foreach (['employees.update', 'records.update'] as $slug) {
                $id = DB::table('permissions')->where('slug', $slug)->value('id');

                if ($id !== null) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $supervisor, 'permission_id' => $id]);
                }
            }
        }
    }

    public function down(): void
    {
        // Not reversed: the parts stay.
    }
};
