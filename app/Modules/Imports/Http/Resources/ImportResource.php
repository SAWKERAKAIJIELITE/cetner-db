<?php

namespace App\Modules\Imports\Http\Resources;

use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use App\Modules\Imports\Enums\ImportSheet;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An import: the file, its state, what the preview found (the counts by sheet) and, on its own page, how each sheet's
 * columns were matched; once written, what it made, or why it failed.
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /** With each sheet's columns (the import's own page, not the history list). */
    public bool $withColumns = false;

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $counts = $this->counts ?? [];
        $empty = ['total' => 0, 'valid' => 0, 'review' => 0, 'invalid' => 0, 'excluded' => 0];

        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            /** skip («تخطي السجل») or update («تحديث السجل الموجود») */
            'duplicates' => $this->duplicates->value,
            'duplicates_label' => $this->duplicates->label(),
            /** pending, applying, approved, failed, rejected, cancelled */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'uploaded_by' => new StaffUserSummaryResource($this->uploader),
            'uploaded_at' => $this->uploaded_at->toIso8601String(),
            'decided_by' => $this->decider ? new StaffUserSummaryResource($this->decider) : null,
            'decided_at' => $this->decided_at?->toIso8601String(),
            /** The reviewer's note, or the reason it was sent back. */
            'decision_note' => $this->decision_note,
            'applied_at' => $this->applied_at?->toIso8601String(),
            /**
             * What it made, once written.
             *
             * @var array{files_created: int, files_updated: int, reviews: int, records: array<string, array{added?: int, corrected?: int}>}|null
             */
            'applied' => $this->applied,
            /**
             * Why writing it failed: nothing of it was kept.
             *
             * @var array{sheet: ?string, sheet_label: ?string, line: ?int, message: string}|null
             */
            'failure' => $this->failure,
            /**
             * «إجمالي السجلات»، «صالحة»، «تحتاج مراجعة»، «غير صالحة»، «مستبعدة».
             *
             * @var array{total: int, valid: int, review: int, invalid: int, excluded: int}
             */
            'counts' => $counts['total'] ?? $empty,
            /** @var list<array{sheet: string, label: string, name: string, header_row: int, counts: array{total: int, valid: int, review: int, invalid: int, excluded: int}, columns?: list<array{letter: string, title: string, status: string, target: ?string, target_label: ?string}>}> */
            'sheets' => array_map(function (array $sheet) {
                $out = [
                    'sheet' => $sheet['sheet'],
                    'label' => ImportSheet::from($sheet['sheet'])->label(),
                    /** The sheet's name in the file. */
                    'name' => $sheet['name'],
                    /** The line of its column titles. */
                    'header_row' => $sheet['header_row'],
                    'counts' => $sheet['counts'],
                ];

                if ($this->withColumns) {
                    /** matched (imported), computed (a workbook formula, computed here), link (a document link, not imported), unmatched (not known: ignored) */
                    $out['columns'] = $sheet['columns'];
                }

                return $out;
            }, $this->sheets ?? []),
            'can_approve' => $this->status === ImportStatus::Pending && $user->hasPermission('entries.review') && (int) (($counts['total'] ?? $empty)['invalid']) === 0,
            'can_reject' => $this->status === ImportStatus::Pending && $user->hasPermission('entries.review'),
            'can_cancel' => $this->status === ImportStatus::Pending && $this->uploaded_by === $user->id,
        ];
    }
}
