<?php

namespace App\Modules\Imports\Enums;

/** «معالجة تكرار الرقم الذاتي»: what a row does with a stored employee or month. */
enum Duplicates: string
{
    /** «تخطي السجل»: the stored one stays as it is; the row is listed with its differences. */
    case Skip = 'skip';

    /** «تحديث السجل الموجود»: the stored one takes the row's values where they differ. */
    case Update = 'update';

    public function label(): string
    {
        return match ($this) {
            self::Skip => 'تخطي السجل',
            self::Update => 'تحديث السجل الموجود',
        };
    }
}
