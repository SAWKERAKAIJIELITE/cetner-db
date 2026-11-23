<?php

namespace App\Modules\Identity\Enums;

/**
 * The roles of the system (D-006). Each staff user holds one. Their permissions are data that the
 * administrator edits (D-015); the defaults are RolesAndPermissionsSeeder's.
 */
enum StaffRole: string
{
    /** Data entry employees: enter and update the employees' files and records. */
    case DataEntry = 'data_entry';

    /** Reviews and approves the data entry employees' entries; manages the settings with the administrator (D-023). */
    case Supervisor = 'supervisor';

    /** Views everything, changes nothing: takes read-only permissions only. */
    case GeneralManager = 'general_manager';

    /** Holds every permission, always (computed, not stored, not editable). */
    case SystemAdmin = 'system_admin';

    public function label(): string
    {
        return match ($this) {
            self::DataEntry => 'مدخل بيانات',
            self::Supervisor => 'المدقق',
            self::GeneralManager => 'المدير العام',
            self::SystemAdmin => 'مدير النظام',
        };
    }
}
