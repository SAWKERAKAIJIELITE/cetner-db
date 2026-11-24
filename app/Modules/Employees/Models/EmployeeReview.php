<?php

namespace App\Modules\Employees\Models;

use App\Modules\Employees\Enums\Cycle;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cycle confirmed for a period (D-036). Written when its change set is approved; append-only.
 *
 * @property int $id
 * @property string $employee_id
 * @property Cycle $cycle
 * @property string $period
 * @property Carbon $reviewed_on
 * @property string $change_set_id
 * @property string $confirmed_by
 * @property string $approved_by
 */
class EmployeeReview extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'cycle' => Cycle::class,
            'reviewed_on' => 'date:Y-m-d',
        ];
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'confirmed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'approved_by');
    }
}
