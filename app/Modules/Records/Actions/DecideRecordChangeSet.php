<?php

namespace App\Modules\Records\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Models\RecordChangeSet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A pending set's outcome (D-041): approved whole, refused with a reason, or taken back by its author. */
final class DecideRecordChangeSet
{
    public function __construct(private readonly ApplyRecordChangeSet $apply) {}

    public function approve(RecordChangeSet $set, StaffUser $reviewer, ?string $note): RecordChangeSet
    {
        return DB::transaction(function () use ($set, $reviewer, $note) {
            $set = $this->lockPending($set);
            self::ensureCanDecide($reviewer, $set);

            $this->apply->handle($set, $reviewer, false, $this->clean($note));

            AuditTrail::record('records.change_approved', $reviewer, [
                'record_type' => $set->record_type->value,
                'change_set_id' => $set->getKey(),
                'self_approved' => false,
            ]);

            return $set->refresh();
        });
    }

    public function reject(RecordChangeSet $set, StaffUser $reviewer, string $note): RecordChangeSet
    {
        return DB::transaction(function () use ($set, $reviewer, $note) {
            $set = $this->lockPending($set);
            self::ensureCanDecide($reviewer, $set);

            $set->forceFill([
                'status' => ChangeSetStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => $this->clean($note),
            ])->save();

            AuditTrail::record('records.change_rejected', $reviewer, [
                'record_type' => $set->record_type->value,
                'change_set_id' => $set->getKey(),
                'reason' => $this->clean($note),
            ]);

            return $set->refresh();
        });
    }

    public function withdraw(RecordChangeSet $set, StaffUser $author): RecordChangeSet
    {
        return DB::transaction(function () use ($set, $author) {
            $set = $this->lockPending($set);

            if ($set->submitted_by !== $author->getKey()) {
                throw new AuthorizationException(__('records.withdraw_own_only'));
            }

            $set->forceFill(['status' => ChangeSetStatus::Withdrawn, 'reviewed_at' => now()])->save();

            AuditTrail::record('records.change_withdrawn', $author, [
                'record_type' => $set->record_type->value,
                'change_set_id' => $set->getKey(),
            ]);

            return $set->refresh();
        });
    }

    /** Nobody approves their own submission (D-023). */
    public static function ensureCanDecide(StaffUser $reviewer, RecordChangeSet $set): void
    {
        if ($set->submitted_by === $reviewer->getKey()) {
            throw new AuthorizationException(__('records.own_change'));
        }
    }

    private function lockPending(RecordChangeSet $set): RecordChangeSet
    {
        $set = RecordChangeSet::query()->lockForUpdate()->findOrFail($set->getKey());

        if (! $set->isPending()) {
            throw ValidationException::withMessages(['change_set' => __('records.not_pending', ['status' => $set->status->label()])]);
        }

        return $set;
    }

    private function clean(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }
}
