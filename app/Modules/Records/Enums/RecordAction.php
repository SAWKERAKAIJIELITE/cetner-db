<?php

namespace App\Modules\Records\Enums;

enum RecordAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'إضافة سجل',
            self::Update => 'تصحيح سجل',
            self::Delete => 'حذف سجل',
        };
    }

    /** The permission an author needs to send it. */
    public function permission(): string
    {
        return 'records.'.$this->value;
    }
}
