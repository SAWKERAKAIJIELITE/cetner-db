<?php

namespace App\Modules\Documents\Models;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Models\EmployeeConductRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One upload of an employee's document (D-049). Pending until a reviewer decides (or approved at once when a
 * reviewer uploads it); the approved one is the current file of its kind until a newer approved upload supersedes
 * it or the administrator removes it. What was uploaded never changes and no row is deleted (a trigger); a refused,
 * withdrawn or removed upload loses its content (`content_purged_at`) but keeps its row.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $kind
 * @property ?string $conduct_record_id
 * @property ChangeSetStatus $status
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property ?string $note
 * @property string $uploaded_by
 * @property Carbon $uploaded_at
 * @property bool $self_approved
 * @property ?string $reviewed_by
 * @property ?Carbon $reviewed_at
 * @property ?string $review_note
 * @property ?Carbon $superseded_at
 * @property ?string $superseded_by_id
 * @property ?Carbon $removed_at
 * @property ?string $removed_by
 * @property ?string $removal_reason
 * @property ?Carbon $content_purged_at
 */
class EmployeeDocument extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => ChangeSetStatus::class,
            'size_bytes' => 'integer',
            'self_approved' => 'boolean',
            'uploaded_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'removed_at' => 'datetime',
            'content_purged_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function conductRecord(): BelongsTo
    {
        return $this->belongsTo(EmployeeConductRecord::class, 'conduct_record_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'uploaded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by');
    }

    public function remover(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'removed_by');
    }

    /** The current file of each kind: approved, neither superseded nor removed. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', ChangeSetStatus::Approved)->whereNull('superseded_at')->whereNull('removed_at');
    }

    public function isPending(): bool
    {
        return $this->status === ChangeSetStatus::Pending;
    }

    public function isCurrent(): bool
    {
        return $this->status === ChangeSetStatus::Approved && $this->superseded_at === null && $this->removed_at === null;
    }

    /** Its content is still on the disk: pending, or approved and not removed (a superseded one stays readable). */
    public function hasContent(): bool
    {
        return $this->content_purged_at === null;
    }

    /** What the upload is in the history: «بانتظار المراجعة», «حالي», «سابق», «أُزيل», «مردود», «مسحوب». */
    public function state(): string
    {
        return match (true) {
            $this->removed_at !== null => 'removed',
            $this->superseded_at !== null => 'superseded',
            $this->status === ChangeSetStatus::Approved => 'current',
            default => $this->status->value,
        };
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'pending' => 'بانتظار المراجعة',
            'current' => 'حالي',
            'superseded' => 'سابق (استُبدل)',
            'removed' => 'أُزيل',
            'rejected' => 'مردود',
            'withdrawn' => 'مسحوب',
        };
    }
}
