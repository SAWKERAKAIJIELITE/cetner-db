<?php

namespace App\Modules\Employees\Enums;

use Carbon\CarbonImmutable;

/**
 * The update cycles of «دليل الحقول» (D-032, D-036). `once` fields are entered once; the four others are
 * confirmed every period, within the branch settings' review window (D-028).
 */
enum Cycle: string
{
    case Once = 'once';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Once => 'لمرة واحدة',
            self::Monthly => 'شهري',
            self::Quarterly => 'ربعي',
            self::SemiAnnual => 'نصف سنوي',
            self::Annual => 'سنوي',
        };
    }

    /** @return list<self> the cycles that are reviewed periodically */
    public static function reviewed(): array
    {
        return [self::Monthly, self::Quarterly, self::SemiAnnual, self::Annual];
    }

    public function isReviewed(): bool
    {
        return $this !== self::Once;
    }

    /** The branch settings' column holding this cycle's review window, in days. */
    public function windowColumn(): string
    {
        return 'review_window_'.$this->value;
    }

    /** The period a day belongs to: 2026-11, 2026-Q4, 2026-H2, 2026. */
    public function periodOf(CarbonImmutable $day): string
    {
        return match ($this) {
            self::Monthly => $day->format('Y-m'),
            self::Quarterly => $day->format('Y').'-Q'.$day->quarter,
            self::SemiAnnual => $day->format('Y').'-H'.($day->month <= 6 ? 1 : 2),
            self::Annual => $day->format('Y'),
            self::Once => 'once',
        };
    }
}
