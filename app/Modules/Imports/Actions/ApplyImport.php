<?php

namespace App\Modules\Imports\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Actions\SubmitEmployeeChanges;
use App\Modules\Employees\Enums\ChangeSetKind;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeSet;
use App\Modules\Employees\Models\EmployeeReview;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Imports\Enums\ImportSheet;
use App\Modules\Imports\Enums\ImportStatus;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Notifications\ImportFinished;
use App\Modules\Imports\Support\ImportRowFailed;
use App\Modules\Records\Actions\SubmitRecordChanges;
use App\Modules\Records\Enums\RecordAction;
use App\Modules\Records\Support\RecordValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes an approved import (D-053), whole or not at all: the files first (new ones, then the stored ones'
 * differences, then the workbook's last reviews), then each register. Every change is the file's or the register's
 * own change set, submitted by the uploader, approved by the reviewer and marked with the import, so the history
 * says where each value came from. The rules are checked again as they are written: anything refused rolls the
 * whole import back and names its sheet and line.
 */
final class ApplyImport
{
    private const CHUNK = 500;

    public function __construct(
        private readonly SubmitEmployeeChanges $files,
        private readonly SubmitRecordChanges $records,
    ) {}

    public function handle(string $importId): void
    {
        @set_time_limit(0);
        @ini_set('memory_limit', (string) config('hr.import_memory'));
        ignore_user_abort(true);
        $import = Import::query()->find($importId);

        if ($import === null || $import->status !== ImportStatus::Applying) {
            return;
        }

        $uploader = StaffUser::query()->findOrFail($import->uploaded_by);
        $approver = StaffUser::query()->findOrFail($import->decided_by);

        try {
            $made = DB::transaction(fn () => $this->write($import, $uploader, $approver));
        } catch (Throwable $e) {
            $failure = $e instanceof ImportRowFailed
                ? ['sheet' => $e->sheet, 'sheet_label' => ImportSheet::from($e->sheet)->label(), 'line' => $e->row, 'message' => $e->getMessage()]
                : ['sheet' => null, 'sheet_label' => null, 'line' => null, 'message' => __('imports.unreadable', ['reason' => class_basename($e)])];

            if (! $e instanceof ImportRowFailed) {
                report($e);
            }

            DB::transaction(function () use ($import, $failure, $uploader, $approver) {
                Import::query()->whereKey($import->id)->update(['status' => ImportStatus::Failed->value, 'failure' => json_encode($failure, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                AuditTrail::record('imports.failed', $approver, ['import_id' => $import->id, ...$failure]);
                self::notify($import->refresh(), $uploader, $approver);
            });

            return;
        }

        DB::transaction(function () use ($import, $made, $uploader, $approver) {
            $import->forceFill(['status' => ImportStatus::Approved, 'applied_at' => now(), 'applied' => $made])->save();
            AuditTrail::record('imports.applied', $approver, ['import_id' => $import->id, 'applied' => $made]);
            self::notify($import, $uploader, $approver);
        });
    }

    /** @return array<string, mixed> what it made */
    private function write(Import $import, StaffUser $uploader, StaffUser $approver): array
    {
        $note = 'استيراد: '.$import->file_name;
        $made = ['files_created' => 0, 'files_updated' => 0, 'reviews' => 0, 'records' => []];
        $employees = [];

        $this->rows($import, ImportSheet::Employees, function (ImportRow $row) use ($import, $uploader, $approver, $note, &$made, &$employees) {
            $values = $row->values;
            $reviews = $values['_reviews'] ?? [];
            unset($values['_reviews']);
            $employee = $row->employee_id === null ? null : Employee::query()->findOrFail($row->employee_id);

            if ($row->action === 'create') {
                $set = $this->files->fromImport(null, $values, $note, $uploader, $approver, $import->id);
                $employee = Employee::query()->findOrFail($set->employee_id);
                $made['files_created']++;
            } elseif ($this->files->fromImport($employee, $values, $note, $uploader, $approver, $import->id) !== null) {
                $made['files_updated']++;
            }

            foreach ($reviews as $cycle => $date) {
                $made['reviews'] += $this->review($employee, Cycle::from($cycle), $date, $note, $uploader, $approver, $import->id);
            }

            $employees[$row->personal_number] = $employee->id;

            if ($row->employee_id === null) {
                $made['_rows'][] = [$row->id, $employee->id, null];
            }
        });

        foreach (array_chunk($made['_rows'] ?? [], self::CHUNK) as $chunk) {
            self::remember($chunk);
        }

        unset($made['_rows']);

        foreach ([ImportSheet::Movements, ImportSheet::Attendance, ImportSheet::Conduct, ImportSheet::Debts] as $sheet) {
            $type = $sheet->recordType();
            $batch = [];
            $flush = function () use (&$batch, $type, $sheet, $note, $uploader, $approver, $import, &$made) {
                if ($batch === []) {
                    return;
                }

                try {
                    $this->records->fromImport($type, array_column($batch, 'item'), $note, $uploader, $approver, $import->id);
                } catch (ValidationException $e) {
                    // A batch is refused as a whole: name its first row.
                    throw new ImportRowFailed($sheet->value, $batch[0]['line'], collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e);
                }

                foreach ($batch as $entry) {
                    $what = $entry['item']['action'] === RecordAction::Create ? 'added' : 'corrected';
                    $made['records'][$sheet->value][$what] = ($made['records'][$sheet->value][$what] ?? 0) + 1;
                }

                self::remember(array_map(fn ($e) => [$e['row'], $e['item']['employee_id'], $e['item']['record_id']], $batch));

                $batch = [];
            };

            $this->rows($import, $sheet, function (ImportRow $row) use (&$batch, $type, $sheet, $employees, $flush) {
                $employeeId = $row->employee_id ?? ($employees[$row->personal_number] ?? null);

                if ($employeeId === null) {
                    throw new ImportRowFailed($sheet->value, $row->line, __('imports.employee_unknown', ['number' => $row->personal_number]));
                }

                if ($row->action === 'correct') {
                    $record = $type->model()::query()->kept()->find($row->record_id);

                    if ($record === null) {
                        throw new ImportRowFailed($sheet->value, $row->line, __('records.change_stale', ['positions' => $row->line]));
                    }

                    $current = RecordValues::of($record);
                    $new = array_intersect_key($row->values, array_filter($row->values, fn ($v, $k) => ($current[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
                    $item = ['action' => RecordAction::Update, 'record_id' => $record->id, 'old_values' => array_intersect_key($current, $new), 'new_values' => $new];
                } else {
                    $item = ['action' => RecordAction::Create, 'record_id' => (string) Str::uuid7(), 'old_values' => null, 'new_values' => $row->values];
                }

                $batch[] = ['row' => $row->id, 'line' => $row->line, 'item' => $item + ['position' => count($batch) + 1, 'employee_id' => $employeeId]];

                if (count($batch) >= self::CHUNK) {
                    $flush();
                }
            });

            $flush();
        }

        return $made;
    }

    /**
     * The rows to write of a sheet, by line: «صالحة» and «تحتاج مراجعة», not the skipped ones.
     *
     * @param  callable(ImportRow): void  $each
     */
    private function rows(Import $import, ImportSheet $sheet, callable $each): void
    {
        $page = 0;

        do {
            $rows = ImportRow::query()->where('import_id', $import->id)->where('sheet', $sheet->value)
                ->whereIn('status', [RowStatus::Valid->value, RowStatus::Review->value])->where('action', '!=', 'skip')
                ->orderBy('line')->forPage(++$page, self::CHUNK)->get();

            foreach ($rows as $row) {
                try {
                    $each($row);
                } catch (ImportRowFailed $e) {
                    throw $e;
                } catch (ValidationException $e) {
                    throw new ImportRowFailed($sheet->value, $row->line, collect($e->errors())->flatten()->first() ?? $e->getMessage(), $e);
                } catch (Throwable $e) {
                    report($e);

                    throw new ImportRowFailed($sheet->value, $row->line, __('imports.unreadable', ['reason' => class_basename($e)]), $e);
                }
            }
        } while ($rows->count() === self::CHUNK);
    }

    /**
     * What each row made, in one statement per page.
     *
     * @param  list<array{0: int, 1: string, 2: ?string}>  $rows  row id, employee id, record id
     */
    private static function remember(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $values = implode(', ', array_fill(0, count($rows), '(?::bigint, ?::uuid, ?::uuid)'));
        DB::update("UPDATE import_rows AS r SET employee_id = v.e, record_id = coalesce(v.rec, r.record_id) FROM (VALUES {$values}) AS v(id, e, rec) WHERE r.id = v.id",
            array_merge(...array_map(fn ($r) => [$r[0], $r[1], $r[2]], $rows)));
    }

    /** The workbook's «آخر مراجعة» of a cycle: a confirmation on that day, unless its period is confirmed already. */
    private function review(Employee $employee, Cycle $cycle, string $date, string $note, StaffUser $uploader, StaffUser $approver, string $importId): int
    {
        $period = $cycle->periodOf(CarbonImmutable::parse($date));
        $taken = EmployeeChangeSet::query()->where('employee_id', $employee->id)->where('kind', ChangeSetKind::Review)
            ->where('cycle', $cycle)->where('period', $period)->whereIn('status', [ChangeSetStatus::Pending, ChangeSetStatus::Approved])->exists();

        if ($taken) {
            return 0;
        }

        $set = new EmployeeChangeSet;
        $set->forceFill([
            'employee_id' => $employee->id,
            'kind' => ChangeSetKind::Review,
            'cycle' => $cycle,
            'period' => $period,
            'status' => ChangeSetStatus::Approved,
            'needs_identity_review' => false,
            'note' => $note,
            'submitted_by' => $uploader->getKey(),
            'submitted_at' => now(),
            'self_approved' => $uploader->getKey() === $approver->getKey(),
            'reviewed_by' => $approver->getKey(),
            'reviewed_at' => now(),
            'import_id' => $importId,
        ])->save();

        $review = new EmployeeReview;
        $review->forceFill([
            'employee_id' => $employee->id,
            'cycle' => $cycle,
            'period' => $period,
            'reviewed_on' => $date,
            'change_set_id' => $set->id,
            'confirmed_by' => $uploader->getKey(),
            'approved_by' => $approver->getKey(),
        ])->save();

        return 1;
    }

    private static function notify(Import $import, StaffUser $uploader, StaffUser $approver): void
    {
        $uploader->notify(new ImportFinished($import, $approver->fullName()));

        if ($approver->getKey() !== $uploader->getKey()) {
            $approver->notify(new ImportFinished($import, $approver->fullName()));
        }
    }
}
