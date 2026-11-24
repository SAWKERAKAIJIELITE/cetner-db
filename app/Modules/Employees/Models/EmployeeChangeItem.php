<?php

namespace App\Modules\Employees\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field of a change set: the approved value when submitted and the new one. Append-only (D-033).
 *
 * @property int $id
 * @property string $change_set_id
 * @property string $field
 * @property mixed $old_value
 * @property mixed $new_value
 */
class EmployeeChangeItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['field', 'old_value', 'new_value'];

    protected function casts(): array
    {
        return ['old_value' => 'json', 'new_value' => 'json'];
    }

    public function changeSet(): BelongsTo
    {
        return $this->belongsTo(EmployeeChangeSet::class, 'change_set_id');
    }
}
