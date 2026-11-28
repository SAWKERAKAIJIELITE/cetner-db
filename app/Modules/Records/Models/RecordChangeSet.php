<?php

namespace App\Modules\Records\Models;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Enums\RecordType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One submission of records of one kind (D-041): a single change or a batch. Pending until a reviewer decides,
 * then the history. Decided once (a trigger), never deleted.
 *
 * @property ?string $import_id the import it came from (D-053)
 * @property string $id
 * @property RecordType $record_type
 * @property ChangeSetStatus $status
 * @property int $items_count
 * @property ?string $note
 * @property string $submitted_by
 * @property Carbon $submitted_at
 * @property bool $self_approved
 * @property ?string $reviewed_by
 * @property ?Carbon $reviewed_at
 * @property ?string $review_note
 */
class RecordChangeSet extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'record_type' => RecordType::class,
            'status' => ChangeSetStatus::class,
            'items_count' => 'integer',
            'self_approved' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecordChangeItem::class, 'change_set_id')->orderBy('position');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === ChangeSetStatus::Pending;
    }
}
