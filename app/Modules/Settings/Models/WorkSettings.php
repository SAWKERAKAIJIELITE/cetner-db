<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * «إعدادات الفرع»: one row, always there (D-028).
 *
 * @property int $id
 * @property string $branch_name
 * @property string $daily_hours
 * @property string $starts_at
 * @property string $ends_at
 * @property int $late_after_minutes
 * @property int $work_days_per_week
 * @property list<int> $weekly_days_off ISO weekdays: 1 Monday … 7 Sunday
 * @property string $currency
 * @property int $annual_leave_days
 * @property int $review_window_monthly
 * @property int $review_window_quarterly
 * @property int $review_window_semi_annual
 * @property int $review_window_annual
 * @property ?string $updated_by
 */
class WorkSettings extends Model
{
    public const EDITABLE = [
        'branch_name', 'daily_hours', 'starts_at', 'ends_at', 'late_after_minutes', 'work_days_per_week', 'weekly_days_off',
        'currency', 'annual_leave_days',
        'review_window_monthly', 'review_window_quarterly', 'review_window_semi_annual', 'review_window_annual',
    ];

    public $incrementing = false;

    protected $table = 'work_settings';

    protected $fillable = self::EDITABLE;

    protected function casts(): array
    {
        return [
            'late_after_minutes' => 'integer',
            'work_days_per_week' => 'integer',
            'weekly_days_off' => 'array',
            'annual_leave_days' => 'integer',
            'review_window_monthly' => 'integer',
            'review_window_quarterly' => 'integer',
            'review_window_semi_annual' => 'integer',
            'review_window_annual' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }
}
