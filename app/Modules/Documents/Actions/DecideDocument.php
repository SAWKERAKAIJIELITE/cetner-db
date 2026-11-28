<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Documents\Support\DocumentStorage;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\FileStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeDerived;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Notifications\Notifications\ChangeRejected;
use App\Modules\Records\Models\EmployeeConductRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An upload's outcome (D-049): approved (it becomes the current file of its kind and the one before it is kept as
 * superseded), refused with a reason (back to its uploader), taken back by its uploader, or, once current, removed
 * by the administrator with a reason. A refused, withdrawn or removed upload loses its content after the commit.
 */
final class DecideDocument
{
    public function approve(EmployeeDocument $document, StaffUser $reviewer, ?string $note): EmployeeDocument
    {
        return DB::transaction(function () use ($document, $reviewer, $note) {
            $document = $this->lockPending($document);

            if ($document->uploaded_by === $reviewer->getKey()) {
                throw new AuthorizationException(__('documents.own_upload'));
            }

            return $this->approveLocked($document, $reviewer, $note, false);
        });
    }

    /** In the caller's transaction, the upload locked and pending: makes it the current file of its kind. */
    public function approveLocked(EmployeeDocument $document, StaffUser $reviewer, ?string $note, bool $self): EmployeeDocument
    {
        $employee = Employee::query()->lockForUpdate()->findOrFail($document->employee_id);

        if (! in_array($employee->file_status, [FileStatus::Pending, FileStatus::Active], true)) {
            throw ValidationException::withMessages(['employee' => __('documents.employee_not_open', ['status' => $employee->file_status->label()])]);
        }

        if ($document->conduct_record_id !== null && ! EmployeeConductRecord::query()->whereKey($document->conduct_record_id)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['conduct_record_id' => __('documents.conduct_record_unknown')]);
        }

        $previous = EmployeeDocument::query()->current()->lockForUpdate()
            ->where('employee_id', $document->employee_id)->where('kind', $document->kind)
            ->when($document->conduct_record_id, fn ($q, $id) => $q->where('conduct_record_id', $id))
            ->first();

        $previous?->forceFill(['superseded_at' => now(), 'superseded_by_id' => $document->getKey()])->save();

        $document->forceFill([
            'status' => ChangeSetStatus::Approved,
            'self_approved' => $self,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => self::clean($note),
        ])->save();

        AuditTrail::record('documents.approved', $reviewer, [
            'document_id' => $document->getKey(),
            'employee_id' => $document->employee_id,
            'kind' => $document->kind,
            'self_approved' => $self,
            'superseded_id' => $previous?->getKey(),
        ]);

        self::refreshFile($employee, $document->kind);

        return $document->refresh();
    }

    public function reject(EmployeeDocument $document, StaffUser $reviewer, string $reason): EmployeeDocument
    {
        return DB::transaction(function () use ($document, $reviewer, $reason) {
            $document = $this->lockPending($document);

            if ($document->uploaded_by === $reviewer->getKey()) {
                throw new AuthorizationException(__('documents.own_upload'));
            }

            $document->forceFill([
                'status' => ChangeSetStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => self::clean($reason),
                'content_purged_at' => now(),
            ])->save();

            AuditTrail::record('documents.rejected', $reviewer, [
                'document_id' => $document->getKey(),
                'employee_id' => $document->employee_id,
                'kind' => $document->kind,
                'reason' => self::clean($reason),
            ]);

            $employee = $document->employee;
            $document->uploader?->notify(new ChangeRejected('documents', $document->getKey(), DocumentCatalogue::label($document->kind),
                $employee?->getKey(), $employee?->fullName(), (string) self::clean($reason), $reviewer->fullName()));

            self::purgeAfterCommit($document);

            return $document->refresh();
        });
    }

    public function withdraw(EmployeeDocument $document, StaffUser $uploader): EmployeeDocument
    {
        return DB::transaction(function () use ($document, $uploader) {
            $document = $this->lockPending($document);

            if ($document->uploaded_by !== $uploader->getKey()) {
                throw new AuthorizationException(__('documents.withdraw_own_only'));
            }

            $document->forceFill(['status' => ChangeSetStatus::Withdrawn, 'reviewed_at' => now(), 'content_purged_at' => now()])->save();

            AuditTrail::record('documents.withdrawn', $uploader, [
                'document_id' => $document->getKey(),
                'employee_id' => $document->employee_id,
                'kind' => $document->kind,
            ]);

            self::purgeAfterCommit($document);

            return $document->refresh();
        });
    }

    /** The administrator takes a current file off (a wrong person's, an unreadable scan): its kind is empty again. */
    public function remove(EmployeeDocument $document, StaffUser $actor, string $reason): EmployeeDocument
    {
        return DB::transaction(function () use ($document, $actor, $reason) {
            $document = EmployeeDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            if (! $document->isCurrent()) {
                throw ValidationException::withMessages(['document' => __('documents.not_current', ['state' => EmployeeDocument::stateLabel($document->state())])]);
            }

            $employee = Employee::query()->lockForUpdate()->findOrFail($document->employee_id);

            $document->forceFill([
                'removed_at' => now(),
                'removed_by' => $actor->getKey(),
                'removal_reason' => self::clean($reason),
                'content_purged_at' => now(),
            ])->save();

            AuditTrail::record('documents.removed', $actor, [
                'document_id' => $document->getKey(),
                'employee_id' => $document->employee_id,
                'kind' => $document->kind,
                'reason' => self::clean($reason),
            ]);

            self::refreshFile($employee, $document->kind);
            self::purgeAfterCommit($document);

            return $document->refresh();
        });
    }

    /** The file's completeness follows its attachments (D-049); an active file only, as for the definitions. */
    private static function refreshFile(Employee $employee, string $kind): void
    {
        if ($employee->file_status !== FileStatus::Active || ! DocumentCatalogue::isFileKind($kind)) {
            return;
        }

        EmployeeDerived::refresh($employee);

        if ($employee->isDirty()) {
            $employee->saveQuietly();
        }
    }

    private static function purgeAfterCommit(EmployeeDocument $document): void
    {
        $disk = $document->disk;
        $path = $document->path;

        DB::afterCommit(fn () => DocumentStorage::delete($disk, $path));
    }

    private function lockPending(EmployeeDocument $document): EmployeeDocument
    {
        $document = EmployeeDocument::query()->lockForUpdate()->findOrFail($document->getKey());

        if (! $document->isPending()) {
            throw ValidationException::withMessages(['document' => __('documents.not_pending', ['status' => $document->status->label()])]);
        }

        return $document;
    }

    private static function clean(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }
}
