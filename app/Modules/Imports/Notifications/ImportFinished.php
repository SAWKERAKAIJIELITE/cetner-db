<?php

namespace App\Modules\Imports\Notifications;

use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\Import;
use App\Modules\Notifications\Notifications\StaffNotice;

/** An approved import was written, or refused while written (D-053): to its uploader and its reviewer. */
class ImportFinished extends StaffNotice
{
    public function __construct(private readonly Import $import, private readonly string $approverName) {}

    public function kind(): string
    {
        return 'import_finished';
    }

    public function title(): string
    {
        return $this->import->status === ImportStatus::Approved ? 'اكتمل الاستيراد' : 'فشل الاستيراد';
    }

    public function message(): string
    {
        if ($this->import->status === ImportStatus::Approved) {
            $made = $this->import->applied ?? [];

            return "استُورد الملف «{$this->import->file_name}»: ".($made['files_created'] ?? 0).' ملفاً جديداً، و'
                .($made['files_updated'] ?? 0).' ملفاً محدَّثاً، و'.array_sum(array_map(fn ($r) => array_sum($r), $made['records'] ?? [])).' سجلاً.';
        }

        $f = $this->import->failure ?? [];

        return "لم يُستورد الملف «{$this->import->file_name}»"
            .(isset($f['line']) ? " (ورقة «{$f['sheet_label']}»، السطر {$f['line']})" : '').': '.($f['message'] ?? '');
    }

    public function details(): array
    {
        return [
            'import_id' => $this->import->id,
            'status' => $this->import->status->value,
            'file_name' => $this->import->file_name,
            'approver_name' => $this->approverName,
            'failure' => $this->import->failure,
        ];
    }
}
