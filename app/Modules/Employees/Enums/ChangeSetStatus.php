<?php

namespace App\Modules\Employees\Enums;

enum ChangeSetStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار المراجعة',
            self::Approved => 'معتمد',
            self::Rejected => 'مردود',
            self::Withdrawn => 'مسحوب',
        };
    }
}
