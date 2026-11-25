<?php

namespace App\Modules\Records\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Records\Enums\RecordAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change of a set: a record added (its values), changed (the approved values beside the new ones, changed
 * fields only) or removed (the record as it was). Append-only (a trigger).
 *
 * @property int $id
 * @property string $change_set_id
 * @property int $position
 * @property RecordAction $action
 * @property string $record_id
 * @property string $employee_id
 * @property ?array<string, mixed> $old_values
 * @property ?array<string, mixed> $new_values
 */
class RecordChangeItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['position', 'action', 'record_id', 'employee_id', 'old_values', 'new_values'];

    protected function casts(): array
    {
        return [
            'action' => RecordAction::class,
            'position' => 'integer',
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function changeSet(): BelongsTo
    {
        return $this->belongsTo(RecordChangeSet::class, 'change_set_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
