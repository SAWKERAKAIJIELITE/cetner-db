<?php

namespace App\Modules\Imports\Models;

use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\Duplicates;
use App\Modules\Imports\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An uploaded workbook (D-053), staged in `import_rows`.
 *
 * @property string $id
 * @property string $file_name
 * @property int $size_bytes
 * @property string $sha256
 * @property Duplicates $duplicates
 * @property ImportStatus $status
 * @property array<int, array<string, mixed>> $sheets
 * @property array<string, mixed> $counts
 * @property string $uploaded_by
 * @property Carbon $uploaded_at
 * @property ?string $decided_by
 * @property ?Carbon $decided_at
 * @property ?string $decision_note
 * @property ?Carbon $applied_at
 * @property ?array<string, mixed> $applied
 * @property ?array<string, mixed> $failure
 */
class Import extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'duplicates' => Duplicates::class,
            'status' => ImportStatus::class,
            'sheets' => 'array',
            'counts' => 'array',
            'applied' => 'array',
            'failure' => 'array',
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'decided_by');
    }
}
