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

    public function label(): string
    {
        return match ($this) {
            self::Create => 'إضافة موظف',
            self::Update => 'تعديل بيانات',
            self::Review => 'مراجعة دورية',
        };
    }
}
