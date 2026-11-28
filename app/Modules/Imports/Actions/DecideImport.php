<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\Import;
use App\Modules\Notifications\Notifications\ChangeRejected;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A staged import's outcome (D-053): approved by a reviewer (then written after the response), sent back with a
 * reason, or taken back by its uploader. It is approved only without a row «غير صالحة»: the owner fixes the
 * workbook and uploads it again, so what is imported is what the workbook says.
 */
final class DecideImport
{
    public function approve(Import $import, StaffUser $reviewer, ?string $note): Import
    {
        $import = DB::transaction(function () use ($import, $reviewer, $note) {
            $import = $this->lockPending($import);
            $invalid = (int) ($import->counts['total']['invalid'] ?? 0);

            if ($invalid > 0) {
                throw ValidationException::withMessages(['import' => __('imports.has_invalid', ['count' => $invalid])]);
            }

            $import->forceFill([
                'status' => ImportStatus::Applying,
                'decided_by' => $reviewer->getKey(),
                'decided_at' => now(),
                'decision_note' => self::clean($note),
            ])->save();

            AuditTrail::record('imports.approved', $reviewer, ['import_id' => $import->id, 'counts' => $import->counts['total'] ?? []]);

            return $import;
        });

        // Written after the response: a large workbook takes minutes; the screen follows the import's status.
        dispatch(fn () => app(ApplyImport::class)->handle($import->id))->afterResponse();

        return $import;
    }

    public function reject(Import $import, StaffUser $reviewer, string $reason): Import
    {
        return DB::transaction(function () use ($import, $reviewer, $reason) {
            $import = $this->lockPending($import);
            $import->forceFill(['status' => ImportStatus::Rejected, 'decided_by' => $reviewer->getKey(), 'decided_at' => now(), 'decision_note' => self::clean($reason)])->save();

            AuditTrail::record('imports.rejected', $reviewer, ['import_id' => $import->id, 'reason' => self::clean($reason)]);
            $import->uploader?->notify(new ChangeRejected('imports', $import->id, 'استيراد «'.$import->file_name.'»', null, null, (string) self::clean($reason), $reviewer->fullName()));

            return $import;
        });
    }

    public function cancel(Import $import, StaffUser $uploader): Import
    {
        return DB::transaction(function () use ($import, $uploader) {
            $import = $this->lockPending($import);

            if ($import->uploaded_by !== $uploader->getKey()) {
                throw new AuthorizationException(__('imports.cancel_own_only'));
            }

            $import->forceFill(['status' => ImportStatus::Cancelled, 'decided_by' => $uploader->getKey(), 'decided_at' => now()])->save();
            AuditTrail::record('imports.cancelled', $uploader, ['import_id' => $import->id]);

            return $import;
        });
    }

    private function lockPending(Import $import): Import
    {
        $import = Import::query()->lockForUpdate()->findOrFail($import->getKey());

        if ($import->status !== ImportStatus::Pending) {
            throw ValidationException::withMessages(['import' => __('imports.not_pending', ['status' => $import->status->label()])]);
        }

        return $import;
    }

    private static function clean(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }
}
