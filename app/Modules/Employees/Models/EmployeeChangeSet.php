<?php

namespace App\Modules\Employees\Models;

use App\Modules\Employees\Enums\ChangeSetKind;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One submission (D-033): a new employee, changes, or a cycle's confirmation. Pending until a reviewer
 * decides; then it is the history. Decided once (a trigger), never deleted.
 *
 * @property ?string $import_id the import it came from (D-053)
 * @property string $id
 * @property string $employee_id
 * @property ChangeSetKind $kind
 * @property ?Cycle $cycle
 * @property ?string $period
 * @property ChangeSetStatus $status
 * @property bool $needs_identity_review
 * @property ?string $note
 * @property string $submitted_by
 * @property Carbon $submitted_at
 * @property bool $self_approved
 * @property ?string $reviewed_by
 * @property ?Carbon $reviewed_at
 * @property ?string $review_note
 */
class EmployeeChangeSet extends Model
{
    use HasUuids;

    /** Set by the Actions only (D-016). */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'kind' => ChangeSetKind::class,
            'cycle' => Cycle::class,
            'status' => ChangeSetStatus::class,
            'needs_identity_review' => 'boolean',
            'self_approved' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeChangeItem::class, 'change_set_id')->orderBy('id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'reviewed_by');
    }

    public function review(): HasOne
    {
        return $this->hasOne(EmployeeReview::class, 'change_set_id');
    }

    public function isPending(): bool
    {
        return $this->status === ChangeSetStatus::Pending;
    }
}
