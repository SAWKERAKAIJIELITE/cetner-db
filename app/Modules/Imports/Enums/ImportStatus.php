<?php

namespace App\Modules\Imports\Enums;

enum ImportStatus: string
{
    /** Staged, waiting for a reviewer: nothing imported yet. */
    case Pending = 'pending';

    /** Approved and being written (after the response). */
    case Applying = 'applying';

    /** Imported. */
    case Approved = 'approved';

    /** Approved, but refused while written: nothing kept (`failure` says why). */
    case Failed = 'failed';

    /** Refused by a reviewer, with a reason. */
    case Rejected = 'rejected';

    /** Taken back by its uploader. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار المراجعة',
            self::Applying => 'قيد الاستيراد',
            self::Approved => 'مستورد',
            self::Failed => 'فشل الاستيراد',
            self::Rejected => 'مردود',
            self::Cancelled => 'ملغى',
        };
    }
}
