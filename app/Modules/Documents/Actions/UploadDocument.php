<?php

namespace App\Modules\Documents\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Documents\Support\DocumentStorage;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\FileStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Models\EmployeeConductRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attaches a document to an employee's file (D-049). Its type is read from its content, never from its name; the
 * same content as the current file or a waiting upload is refused. Data entry's upload waits for a reviewer; a
 * reviewer's is approved at once (as their own changes are, D-023) and replaces the current file of its kind.
 */
final class UploadDocument
{
    public function __construct(private readonly DecideDocument $decide) {}

    public function handle(Employee $employee, string $kind, UploadedFile $file, ?string $conductRecordId, ?string $note, StaffUser $actor): EmployeeDocument
    {
        $mime = DocumentStorage::sniff($file);

        if (! isset(DocumentCatalogue::MIME_TYPES[$mime])) {
            throw ValidationException::withMessages(['file' => __('documents.type_not_allowed')]);
        }

        $hash = hash_file('sha256', $file->getRealPath());
        $id = (string) Str::uuid7();
        $path = DocumentStorage::put($employee->getKey(), $id, DocumentCatalogue::MIME_TYPES[$mime], $file);

        try {
            return DB::transaction(function () use ($employee, $kind, $file, $conductRecordId, $note, $actor, $mime, $hash, $id, $path) {
                $employee = Employee::query()->lockForUpdate()->findOrFail($employee->getKey());

                if (! in_array($employee->file_status, [FileStatus::Pending, FileStatus::Active], true)) {
                    throw ValidationException::withMessages(['employee' => __('documents.employee_not_open', ['status' => $employee->file_status->label()])]);
                }

                $this->ensureConductRecord($employee, $kind, $conductRecordId);
                $this->ensureNew($employee, $kind, $conductRecordId, $hash);

                $document = new EmployeeDocument;
                $document->forceFill([
                    'id' => $id,
                    'employee_id' => $employee->getKey(),
                    'kind' => $kind,
                    'conduct_record_id' => $conductRecordId,
                    'status' => ChangeSetStatus::Pending,
                    'disk' => DocumentStorage::disk(),
                    'path' => $path,
                    'original_name' => self::cleanName($file->getClientOriginalName(), DocumentCatalogue::MIME_TYPES[$mime]),
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => $hash,
                    'note' => $note === null || trim($note) === '' ? null : trim($note),
                    'uploaded_by' => $actor->getKey(),
                    'uploaded_at' => now(),
                ])->save();

                AuditTrail::record('documents.uploaded', $actor, [
                    'document_id' => $id,
                    'employee_id' => $employee->getKey(),
                    'kind' => $kind,
                    'conduct_record_id' => $conductRecordId,
                    'mime' => $mime,
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => $hash,
                ]);

                // A reviewer's own upload applies at once, and says so (D-023).
                if ($actor->hasPermission('entries.review')) {
                    return $this->decide->approveLocked($document, $actor, null, true);
                }

                return $document;
            });
        } catch (Throwable $e) {
            // Nothing was recorded: nothing is kept.
            DocumentStorage::delete(DocumentStorage::disk(), $path);

            throw $e;
        }
    }

    private function ensureConductRecord(Employee $employee, string $kind, ?string $conductRecordId): void
    {
        if ($kind === DocumentCatalogue::CONDUCT && $conductRecordId === null) {
            throw ValidationException::withMessages(['conduct_record_id' => __('documents.conduct_record_needed')]);
        }

        if ($kind !== DocumentCatalogue::CONDUCT && $conductRecordId !== null) {
            throw ValidationException::withMessages(['conduct_record_id' => __('documents.conduct_record_only')]);
        }

        if ($conductRecordId !== null && ! EmployeeConductRecord::query()->whereKey($conductRecordId)
            ->where('employee_id', $employee->getKey())->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['conduct_record_id' => __('documents.conduct_record_unknown')]);
        }
    }

    private function ensureNew(Employee $employee, string $kind, ?string $conductRecordId, string $hash): void
    {
        $same = EmployeeDocument::query()
            ->where('employee_id', $employee->getKey())->where('kind', $kind)
            ->when($conductRecordId, fn ($q, $id) => $q->where('conduct_record_id', $id))
            ->where('sha256', $hash);

        if ((clone $same)->current()->exists()) {
            throw ValidationException::withMessages(['file' => __('documents.same_as_current')]);
        }

        if ((clone $same)->where('status', ChangeSetStatus::Pending)->exists()) {
            throw ValidationException::withMessages(['file' => __('documents.same_as_pending')]);
        }
    }

    /** The name as uploaded, for the download's name only: no path, no control characters, its real extension. */
    public static function cleanName(string $name, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[\x00-\x1F\x7F\/:*?"<>|]+/u', ' ', $base));
        $base = mb_substr($base === '' ? 'document' : $base, 0, 200);

        return $base.'.'.$extension;
    }
}
