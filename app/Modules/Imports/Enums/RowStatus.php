<?php

namespace App\Modules\Imports\Enums;

/** A staged row (the frontend's preview): «صالحة», «تحتاج مراجعة», «غير صالحة», «مستبعدة». */
enum RowStatus: string
{
    /** Imported as it is. */
    case Valid = 'valid';

    /** Imported, but something deserves a look: a likely duplicate, a value to check. */
    case Review = 'review';

    /** Not importable: a wrong, missing or conflicting value. The import cannot be approved while one is left. */
    case Invalid = 'invalid';

    /** Left out: already stored, or nothing in it. */
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'صالحة',
            self::Review => 'تحتاج مراجعة',
            self::Invalid => 'غير صالحة',
            self::Excluded => 'مستبعدة',
        };
    }
}
