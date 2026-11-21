<?php

namespace App\Modules\Identity\Enums;

enum DeviceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'بانتظار الموافقة',
            self::Approved => 'معتمد',
            self::Revoked => 'ملغى',
        };
    }
}
