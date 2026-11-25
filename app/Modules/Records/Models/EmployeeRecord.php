<?php

namespace App\Modules\Records\Models;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\RecordCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of a workbook record (D-040), written only by an approved change (D-041) and never deleted: removing it
 * marks it (`deleted_at`), and the change that removed it keeps it as it was.
 *
 * @property string $id
 * @property string $employee_id
 * @property ?string $notes
 * @property string $created_by
 * @property string $approved_by
 * @property ?Carbon $deleted_at
 * @property ?string $deleted_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
abstract class EmployeeRecord extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** Written by the Actions only (D-016). */
    protected $fillable = [];

    abstract public function recordType(): RecordType;

    protected function casts(): array
    {
        $casts = ['deleted_at' => 'datetime'];

        foreach (RecordCatalogue::fields($this->recordType()) as $key => $field) {
            $casts[$key] = match ($field['type']) {
                'date' => 'date:Y-m-d',
                'int', 'list' => 'integer',
                default => null,
            };
        }

        return array_filter($casts);
    }

    /** @param Builder<static> $query */
    public function scopeKept(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('deleted_at'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'approved_by');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
