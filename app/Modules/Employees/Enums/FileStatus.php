<?php

namespace App\Modules\Employees\Enums;

/** Where the file stands, apart from the person's own status (فعال / غير فعال, a list). */
enum FileStatus: string
{
    /** Added, waiting for its first approval: its values are in the change set. */
    case Pending = 'pending';

    case Active = 'active';

    /** Its addition was refused. */
    case Rejected = 'rejected';

    /** Its author took the addition back before review. */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار اعتماد الإضافة',
            self::Active => 'معتمد',
            self::Rejected => 'رُدّت إضافته',
            self::Withdrawn => 'سُحبت إضافته',
        };
    }
}
