<?php

namespace App\Modules\Employees\Enums;

enum ChangeSetKind: string
{
    /** A new employee: every value is new. */
    case Create = 'create';

    /** Changes to some fields. */
    case Update = 'update';

    /** The confirmation of a cycle for the current period, with the changes found (or none). */
    case Review = 'review';

    /** What an approved movement makes of the file (D-048): applied at once, its reviewer having approved it. */
    case Movement = 'movement';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'إضافة موظف',
            self::Update => 'تعديل بيانات',
            self::Review => 'مراجعة دورية',
            self::Movement => 'تطبيق حركة',
        };
    }
}
