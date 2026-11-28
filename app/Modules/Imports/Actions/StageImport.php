<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\Duplicates;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Support\ImportStager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * An uploaded workbook, checked and staged (D-053): refused whole when it is not an .xlsx, holds macros, was
 * imported already, or lacks a required column; otherwise every row is staged with what is wrong with it, for a
 * reviewer to approve. Nothing of the files and registers changes here.
 */
final class StageImport
{
    public function handle(UploadedFile $file, Duplicates $duplicates, StaffUser $actor): Import
    {
        $path = $file->getRealPath();
        $this->ensureWorkbook($path);

        $hash = hash_file('sha256', $path);
        $previous = Import::query()->where('sha256', $hash)->whereIn('status', [ImportStatus::Pending, ImportStatus::Applying, ImportStatus::Approved])
            ->orderByDesc('uploaded_at')->first();

        if ($previous !== null) {
            throw ValidationException::withMessages(['file' => $previous->status === ImportStatus::Pending
                ? __('imports.already_pending')
                : __('imports.already_imported', ['date' => $previous->uploaded_at->setTimezone(config('hr.timezone'))->format('Y-m-d')])]);
        }

        @set_time_limit(0);
        @ini_set('memory_limit', (string) config('hr.import_memory'));

        try {
            $staged = (new ImportStager($duplicates))->stage($path);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages(['file' => __('imports.unreadable', ['reason' => class_basename($e)])]);
        }

        return DB::transaction(function () use ($file, $duplicates, $actor, $hash, $staged) {
            $import = new Import;
            $import->forceFill([
                'file_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'size_bytes' => (int) $file->getSize(),
                'sha256' => $hash,
                'duplicates' => $duplicates,
                'status' => ImportStatus::Pending,
                'sheets' => $staged['sheets'],
                'counts' => $staged['counts'],
                'uploaded_by' => $actor->getKey(),
                'uploaded_at' => now(),
            ])->save();

            foreach (array_chunk($staged['rows'], 500) as $chunk) {
                DB::table('import_rows')->insert(array_map(fn (array $row) => [
                    'import_id' => $import->id,
                    'sheet' => $row['sheet'],
                    'line' => $row['line'],
                    'status' => $row['status'],
                    'action' => $row['action'],
                    'personal_number' => $row['personal_number'],
                    'employee_id' => $row['employee_id'],
                    'record_id' => $row['record_id'],
                    'cells' => json_encode($row['cells'], JSON_UNESCAPED_UNICODE),
                    'values' => json_encode((object) $row['values'], JSON_UNESCAPED_UNICODE),
                    'changes' => $row['changes'] === null ? null : json_encode($row['changes'], JSON_UNESCAPED_UNICODE),
                    'issues' => json_encode($row['issues'], JSON_UNESCAPED_UNICODE),
                ], $chunk));
            }

            AuditTrail::record('imports.uploaded', $actor, [
                'import_id' => $import->id,
                'file_name' => $import->file_name,
                'size_bytes' => $import->size_bytes,
                'sha256' => $hash,
                'duplicates' => $duplicates->value,
                'counts' => $staged['counts']['total'],
            ]);

            return $import;
        });
    }

    /** An .xlsx (a zip with a workbook), without macros. */
    private function ensureWorkbook(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['file' => __('imports.not_xlsx')]);
        }

        $types = (string) $zip->getFromName('[Content_Types].xml');
        $isWorkbook = $zip->locateName('xl/workbook.xml') !== false;
        $macros = $zip->locateName('xl/vbaProject.bin') !== false || str_contains($types, 'macroEnabled') || str_contains($types, 'vbaProject');
        $zip->close();

        if (! $isWorkbook) {
            throw ValidationException::withMessages(['file' => __('imports.not_xlsx')]);
        }

        if ($macros) {
            throw ValidationException::withMessages(['file' => __('imports.has_macros')]);
        }
    }
}
