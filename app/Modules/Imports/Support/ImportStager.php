<?php

namespace App\Modules\Imports\Support;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\FieldValues;
use App\Modules\Employees\Support\IdentityLock;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Imports\Enums\Duplicates;
use App\Modules\Imports\Enums\ImportSheet;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\MovementEffects;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordLabels;
use App\Modules\Records\Support\RecordValues;
use App\Support\ArabicText;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads a workbook and says, row by row, what an import would do and what is wrong (D-053): the employees first,
 * then each register that is present. Nothing is written but the staged rows. A row is «غير صالحة» when a value
 * is wrong, missing or in conflict with what is stored or with another row; «تحتاج مراجعة» when it imports but
 * deserves a look (a likely duplicate, a workbook warning); «مستبعدة» when it is already stored.
 */
final class ImportStager
{
    private ImportLookups $lookups;

    private ValueLabels $labels;

    /** @var array<int, string>|null movement type id => code */
    private ?array $movementCodes = null;

    /** @var array<string, array{line: int, status: RowStatus, employee_id: ?string, name: ?string}> «الرقم الذاتي» => the employees' row */
    private array $fileRows = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<array<string, mixed>> */
    private array $sheets = [];

    /** @var list<string> */
    private array $refusals = [];

    public function __construct(private readonly Duplicates $duplicates)
    {
        $this->lookups = new ImportLookups;
        $this->labels = new ValueLabels;
    }

    /**
     * @return array{sheets: list<array<string, mixed>>, rows: list<array<string, mixed>>, counts: array<string, mixed>}
     */
    public function stage(string $path): array
    {
        $reader = new WorkbookReader($path);
        $present = $reader->sheets();

        if ($present === []) {
            throw ValidationException::withMessages(['file' => __('imports.no_known_sheet')]);
        }

        // The employees first: the registers name them by «الرقم الذاتي».
        foreach (ImportSheet::cases() as $sheet) {
            if (isset($present[$sheet->value])) {
                $this->readSheet($reader, $sheet, $present[$sheet->value]);
            }
        }

        if ($this->refusals !== []) {
            throw ValidationException::withMessages(['file' => $this->refusals]);
        }

        if ($this->rows === []) {
            throw ValidationException::withMessages(['file' => __('imports.nothing_to_import')]);
        }

        return ['sheets' => $this->sheets, 'rows' => $this->rows, 'counts' => self::counts($this->rows)];
    }

    private function readSheet(WorkbookReader $reader, ImportSheet $sheet, string $name): void
    {
        $columns = [];
        $headerRow = 0;
        $raw = [];

        $reader->read($sheet, $name, function (int $row, array $found) use (&$columns, &$headerRow) {
            [$headerRow, $columns] = [$row, $found];
        }, function (int $line, array $values) use (&$raw) {
            $raw[$line] = $values;
        });

        if ($columns === []) {
            $this->refusals[] = __('imports.no_header', ['sheet' => $name]);

            return;
        }

        $targets = array_column(array_filter($columns, fn ($c) => $c['target'] !== null), 'target');
        $keys = array_map(fn ($t) => ImportColumns::key($t), $targets);
        $missing = array_filter(ImportColumns::required($sheet), fn ($t) => ! in_array(ImportColumns::key($t), $keys, true));

        if ($missing !== []) {
            $this->refusals[] = __('imports.missing_columns', ['sheet' => $name, 'columns' => implode('، ', array_map(fn ($t) => '«'.ImportColumns::label($sheet, $t).'»', $missing))]);

            return;
        }

        // Only the input columns: a row made only of formulas (the workbook's empty template lines) is no row.
        $inputs = array_filter($columns, fn ($c) => $c['target'] !== null && $c['target'][0] !== ImportColumns::COMPUTED);
        $before = count($this->rows);
        $parsed = [];

        foreach ($raw as $line => $values) {
            $cells = [];

            $typed = false;

            foreach ($inputs as $c) {
                if (! array_key_exists($c['index'], $values)) {
                    continue;
                }

                $value = $values[$c['index']];

                if ($value instanceof FormulaValue) {
                    // A formula in an input column: its value is taken for a field, never for the checks.
                    if ($c['target'][0] === ImportColumns::FIELD && $value->value !== null) {
                        $cells[$c['index']] = $value->value;
                    }

                    continue;
                }

                $cells[$c['index']] = $value;
                $typed = true;
            }

            if ($typed) {
                $parsed[$line] = $cells;
            }
        }

        unset($raw);

        $sheet === ImportSheet::Employees ? $this->employees($parsed, $inputs) : $this->records($sheet, $parsed, $inputs);

        $staged = array_slice($this->rows, $before);
        $this->sheets[] = [
            'sheet' => $sheet->value,
            'label' => $sheet->label(),
            'name' => $name,
            'header_row' => $headerRow,
            'columns' => array_map(fn ($c) => [
                'letter' => $c['letter'],
                'title' => $c['title'],
                /** matched (imported), computed (a formula: computed here), link (not imported), unmatched (not known: ignored) */
                'status' => match (true) {
                    $c['target'] === null => 'unmatched',
                    $c['target'][0] === ImportColumns::COMPUTED => 'computed',
                    $c['target'][0] === ImportColumns::LINK => 'link',
                    default => 'matched',
                },
                'target' => $c['target'] === null || $c['target'][0] === ImportColumns::COMPUTED ? null : ImportColumns::key($c['target']),
                'target_label' => $c['target'] === null || $c['target'][0] === ImportColumns::COMPUTED ? null : ImportColumns::label($sheet, $c['target']),
            ], $columns),
            'counts' => self::counts($staged)['total'],
        ];
    }

    // ── The employees ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<int, mixed>>  $parsed  line => column index => value
     * @param  array<int, array{index: int, letter: string, title: string, target: array{0: string, 1: string|int|null}}>  $columns
     */
    private function employees(array $parsed, array $columns): void
    {
        $byIndex = array_column($columns, null, 'index');
        $rows = [];

        foreach ($parsed as $line => $cells) {
            $rows[$line] = $this->employeeRow($line, $cells, $byIndex);
        }

        // What is stored, in a few queries.
        $numbers = array_values(array_unique(array_filter(array_column($rows, 'personal_number'))));
        $nationals = array_values(array_unique(array_filter(array_map(fn ($r) => $r['values']['national_number'] ?? null, $rows))));
        $stored = [];

        foreach (array_chunk($numbers, 1000) as $chunk) {
            foreach (Employee::query()->whereIn('personal_number', $chunk)->get() as $e) {
                $stored[$e->personal_number] = $e;
            }
        }

        $nationalOwners = [];

        foreach (array_chunk($nationals, 1000) as $chunk) {
            $nationalOwners += Employee::query()->whereIn('national_number', $chunk)->pluck('personal_number', 'national_number')->all();
        }

        $waiting = $this->waitingNumbers();
        $pendingSets = $stored === [] ? [] : DB::table('employee_change_sets')->whereIn('employee_id', array_map(fn ($e) => $e->id, $stored))
            ->where('status', ChangeSetStatus::Pending->value)->distinct()->pluck('employee_id')->flip()->all();

        // The same number twice in the file.
        $linesOf = fn (string $field) => collect($rows)->filter(fn ($r) => ($field === 'personal_number' ? $r['personal_number'] : ($r['values'][$field] ?? null)) !== null)
            ->groupBy(fn ($r) => $field === 'personal_number' ? $r['personal_number'] : $r['values'][$field])->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->pluck('line')->all());
        $twice = $linesOf('personal_number');
        $nationalTwice = $linesOf('national_number');

        // Same person, another number: the name and the birth date.
        $people = [];

        foreach ($rows as $r) {
            if (($r['values']['birth_date'] ?? null) !== null && $r['name_key'] !== null) {
                $people[$r['name_key'].'|'.$r['values']['birth_date']][] = $r;
            }
        }

        $births = array_values(array_unique(array_filter(array_map(fn ($r) => $r['values']['birth_date'] ?? null, $rows))));
        $lookalikes = [];

        foreach (array_chunk($births, 500) as $chunk) {
            foreach (Employee::query()->where('file_status', 'active')->whereIn('birth_date', $chunk)->get(['id', 'personal_number', 'first_name', 'father_name', 'family_name', 'birth_date', 'search_name']) as $e) {
                $lookalikes[ArabicText::searchKey((string) $e->fullName()).'|'.$e->birth_date->format('Y-m-d')][] = $e;
            }
        }

        foreach ($rows as $line => $r) {
            $issues = $r['issues'];
            $values = $r['values'];
            $number = $r['personal_number'];
            $existing = $number === null ? null : ($stored[$number] ?? null);
            $action = 'create';
            $changes = null;

            if ($number === null) {
                $issues[] = self::issue('error', 'field:personal_number', __('imports.personal_number_missing'));
            } elseif (isset($twice[$number])) {
                $issues[] = self::issue('error', 'field:personal_number', __('imports.personal_number_twice', ['number' => $number, 'lines' => implode('، ', $twice[$number])]));
            }

            if (($nn = $values['national_number'] ?? null) !== null) {
                if (isset($nationalTwice[$nn])) {
                    $issues[] = self::issue('error', 'field:national_number', __('imports.national_number_twice', ['number' => $nn, 'lines' => implode('، ', $nationalTwice[$nn])]));
                }

                if (isset($nationalOwners[$nn]) && $nationalOwners[$nn] !== $number) {
                    $issues[] = self::issue('error', 'field:national_number', __('imports.national_number_taken', ['number' => $nn, 'other' => $nationalOwners[$nn] ?? '—']));
                }
            }

            foreach (['personal_number', 'national_number'] as $field) {
                $value = $field === 'personal_number' ? $number : ($values[$field] ?? null);

                if ($value !== null && isset($waiting[$field][$value]) && ($existing === null || $waiting[$field][$value] !== $existing->id)) {
                    $issues[] = self::issue('error', 'field:'.$field, __('imports.number_waiting', ['field' => FieldCatalogue::get($field)['label'], 'number' => $value]));
                }
            }

            if ($existing !== null && ! $existing->isActive()) {
                $existing = null;
            }

            if ($existing === null) {
                $missing = array_filter(['national_number', 'first_name', 'father_name', 'family_name'], fn ($k) => ($values[$k] ?? null) === null && ! self::hasError($issues, 'field:'.$k));

                if ($missing !== []) {
                    $issues[] = self::issue('error', null, __('imports.new_file_missing', ['fields' => implode('، ', array_map(fn ($k) => '«'.FieldCatalogue::get($k)['label'].'»', $missing))]));
                }

                $this->contractOrder($values, [], $issues);

                foreach ($people[$r['name_key'].'|'.($values['birth_date'] ?? '')] ?? [] as $other) {
                    if ($other['line'] !== $line && $other['personal_number'] !== $number) {
                        $issues[] = self::issue('warning', null, __('imports.likely_duplicate_in_file', ['line' => $other['line']]));
                    }
                }

                foreach ($lookalikes[$r['name_key'].'|'.($values['birth_date'] ?? '')] ?? [] as $other) {
                    $issues[] = self::issue('warning', null, __('imports.likely_duplicate', ['number' => $other->personal_number, 'name' => $other->fullName()]));
                }
            } else {
                $diff = [];

                foreach ($values as $key => $value) {
                    $current = FieldValues::current($existing, $key);

                    if ($current !== $value) {
                        $diff[$key] = [$current, $value];
                    }
                }

                if ($this->duplicates === Duplicates::Skip) {
                    $action = 'skip';
                    $issues[] = self::issue('info', null, __('imports.exists_skipped', ['number' => $number]));

                    foreach ($diff as $key => [$old, $new]) {
                        $issues[] = self::issue('warning', 'field:'.$key, $this->differs($key, $old, $new));
                    }
                } else {
                    $action = 'update';
                    $changes = $diff;

                    if (IdentityLock::isLocked($existing)) {
                        foreach (array_intersect(array_keys($diff), FieldCatalogue::identityKeys()) as $key) {
                            $issues[] = self::issue('error', 'field:'.$key, __('imports.identity_locked', [
                                'field' => FieldCatalogue::get($key)['label'],
                                'stored' => $this->labels->display($key, $diff[$key][0]) ?? '—',
                                'file' => $this->labels->display($key, $diff[$key][1]) ?? '—',
                            ]));
                        }
                    }

                    $this->contractOrder($values, $existing->only(['contract_starts_on', 'contract_ends_on']), $issues, $existing);

                    if (isset($pendingSets[$existing->id]) && $diff !== []) {
                        $issues[] = self::issue('warning', null, __('imports.pending_changes'));
                    }
                }

                $r['reviews'] = $this->newerReviews($existing, $r['reviews'], $issues);

                if ($action === 'update' && $diff === [] && $r['reviews'] === []) {
                    $action = 'skip';
                    $issues[] = self::issue('info', null, __('imports.exists_same'));
                }
            }

            $this->workbookWarnings($values, $issues);

            $status = self::status($issues, $action === 'skip');
            $this->fileRows[$number ?? '#'.$line] ??= ['line' => $line, 'status' => $status, 'employee_id' => $existing?->id, 'name' => ArabicText::searchKey((string) ($existing?->fullName() ?? $r['full_name']))];

            if ($number !== null && isset($twice[$number])) {
                $this->fileRows[$number]['status'] = RowStatus::Invalid;
            }

            $this->rows[] = [
                'sheet' => ImportSheet::Employees->value,
                'line' => $line,
                'status' => $status->value,
                'action' => $action,
                'personal_number' => $number,
                'employee_id' => $existing?->id,
                'record_id' => null,
                'cells' => $r['cells'],
                'values' => $values + ($r['reviews'] === [] ? [] : ['_reviews' => $r['reviews']]),
                'changes' => $changes === null ? null : array_map(fn ($pair) => ['from' => $pair[0], 'to' => $pair[1]], $changes),
                'issues' => $issues,
            ];
        }
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<int, array{index: int, letter: string, title: string, target: array{0: string, 1: string|int|null}}>  $columns
     * @return array<string, mixed>
     */
    private function employeeRow(int $line, array $cells, array $columns): array
    {
        $values = [];
        $issues = [];
        $display = [];
        $levels = array_fill(1, 6, null);
        $work = [2 => null, 3 => null];
        $reviews = [];
        $today = BusinessDay::today()->format('Y-m-d');

        foreach ($cells as $index => $raw) {
            $column = $columns[$index];
            [$what, $which] = $column['target'];
            $text = ImportCell::text($raw);
            $display[$column['title']] = $text;
            $key = ImportColumns::key($column['target']);

            switch ($what) {
                case ImportColumns::FIELD:
                    [$value, $error, $warning] = $this->fieldValue($which, $raw);

                    if ($error !== null) {
                        $issues[] = self::issue('error', $key, $error);
                    } else {
                        $values[$which] = $value;
                    }

                    if ($warning !== null) {
                        $issues[] = self::issue('warning', $key, $warning);
                    }

                    break;
                case ImportColumns::ORG_LEVEL:
                    $levels[$which] = $text;

                    break;
                case ImportColumns::WORK_LEVEL:
                    $work[$which] = $text;

                    break;
                case ImportColumns::REVIEW:
                    $date = ImportCell::date($raw);

                    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
                        $issues[] = self::issue('error', $key, __('validation.date_format', ['attribute' => $column['title'], 'format' => 'Y-m-d']));
                    } elseif ($date > $today) {
                        $issues[] = self::issue('error', $key, __('imports.review_future', ['column' => $column['title'], 'date' => $date]));
                    } else {
                        $reviews[$which] = $date;
                    }

                    break;
                case ImportColumns::LINK:
                    $issues[] = self::issue('info', $key, __('imports.link_not_imported', ['column' => $column['title']]));

                    break;
            }
        }

        [$unit, $error] = $this->lookups->unit($levels, 'جهة العمل');

        if ($error !== null) {
            $issues[] = self::issue('error', 'org_level:1', $error);
        } elseif ($unit !== null) {
            $values['org_unit_id'] = $unit;
        }

        if ($work[2] !== null || $work[3] !== null) {
            [$current, $error] = $this->lookups->unit([1 => $levels[1], 2 => $work[2], 3 => $work[3]], 'جهة العمل الحالية');

            if ($error !== null) {
                $issues[] = self::issue('error', 'work_level:2', $error);
            } else {
                $values['current_work_unit_id'] = $current;
            }
        }

        // The rules of each value, with their Arabic names; a wrong one is left out so the others are still read.
        try {
            // The items and units were found active by ImportLookups: no query per value.
            $values = FieldValues::validate($values, false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $field = str_starts_with($field, 'values.') ? substr($field, 7) : null;
                $issues[] = self::issue('error', $field === null ? null : self::fieldTarget($field), $messages[0]);
                unset($values[$field]);
            }

            $values = $values === [] ? [] : FieldValues::validate($values, false);
        }

        return [
            'line' => $line,
            'personal_number' => $values['personal_number'] ?? null,
            'full_name' => trim(implode(' ', array_filter([$values['first_name'] ?? null, $values['father_name'] ?? null, $values['family_name'] ?? null]))),
            'name_key' => ($values['first_name'] ?? null) === null || ($values['father_name'] ?? null) === null || ($values['family_name'] ?? null) === null ? null
                : ArabicText::searchKey(trim(implode(' ', array_filter([$values['first_name'] ?? null, $values['father_name'] ?? null, $values['family_name'] ?? null])))),
            'values' => $values,
            'reviews' => $reviews,
            'cells' => $display,
            'issues' => $issues,
        ];
    }

    private static function fieldTarget(string $field): string
    {
        return in_array($field, ['org_unit_id'], true) ? 'org_level:1' : ($field === 'current_work_unit_id' ? 'work_level:2' : 'field:'.$field);
    }

    /**
     * A cell as the field takes it.
     *
     * @return array{0: mixed, 1: ?string, 2: ?string} the value, an error, a warning
     */
    private function fieldValue(string $key, mixed $raw): array
    {
        $field = FieldCatalogue::get($key);
        $text = ImportCell::text($raw);

        return match ($field['type']) {
            'list' => (function () use ($field, $text) {
                [$id, $error] = $this->lookups->item($field['list'], $text, $field['label']);

                return [$id, $error, null];
            })(),
            'bool' => ($b = ImportCell::bool($raw)) === null ? [null, __('imports.not_yes_no', ['value' => $text]), null] : [$b, null, null],
            'date' => [ImportCell::date($raw), null, null],
            'phone' => [$text, null, (is_int($raw) || is_float($raw)) && ! str_starts_with($text, '0') ? __('imports.phone_as_number', ['field' => $field['label'], 'value' => $text]) : null],
            default => [$text, null, null],
        };
    }

    /** @return array<string, array<string, string>> field => value => the file's id, for numbers waiting in a change */
    private function waitingNumbers(): array
    {
        $out = [];
        $rows = DB::table('employee_change_items AS i')->join('employee_change_sets AS s', 's.id', '=', 'i.change_set_id')
            ->where('s.status', ChangeSetStatus::Pending->value)->whereIn('i.field', ['personal_number', 'national_number'])
            ->get(['i.field', 'i.new_value', 's.employee_id']);

        foreach ($rows as $row) {
            $out[$row->field][(string) json_decode($row->new_value)] = $row->employee_id;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $stored
     * @param  list<array<string, mixed>>  $issues
     */
    private function contractOrder(array $values, array $stored, array &$issues, ?Employee $existing = null): void
    {
        $starts = $values['contract_starts_on'] ?? ($existing ? FieldValues::current($existing, 'contract_starts_on') : null);
        $ends = $values['contract_ends_on'] ?? ($existing ? FieldValues::current($existing, 'contract_ends_on') : null);

        if ($starts !== null && $ends !== null && $ends < $starts) {
            $issues[] = self::issue('error', 'field:contract_ends_on', __('imports.contract_order'));
        }
    }

    /**
     * The workbook's last reviews that are newer than the stored ones (an older date is not imported).
     *
     * @param  array<string, string>  $reviews  cycle => date
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, string>
     */
    private function newerReviews(Employee $existing, array $reviews, array &$issues): array
    {
        if ($reviews === [] || $this->duplicates === Duplicates::Skip) {
            return $this->duplicates === Duplicates::Skip ? [] : $reviews;
        }

        $stored = DB::table('employee_reviews')->where('employee_id', $existing->id)->groupBy('cycle')
            ->selectRaw('cycle, max(reviewed_on) AS last')->pluck('last', 'cycle')->all();

        foreach ($reviews as $cycle => $date) {
            $last = isset($stored[$cycle]) ? substr((string) $stored[$cycle], 0, 10) : null;

            if ($last !== null && $date <= $last) {
                $issues[] = self::issue('info', 'review:'.$cycle, __('imports.review_older', ['column' => ImportColumns::label(ImportSheet::Employees, [ImportColumns::REVIEW, $cycle]), 'date' => $date, 'stored' => $last]));
                unset($reviews[$cycle]);
            }
        }

        return $reviews;
    }

    /**
     * The workbook's own warnings (D-037) on the values imported.
     *
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $issues
     */
    private function workbookWarnings(array $values, array &$issues): void
    {
        $job = isset($values['job_status_id']) ? $this->labels->item('job_status_id', $values['job_status_id'])['code'] ?? null : null;
        $status = isset($values['employee_status_id']) ? $this->labels->item('employee_status_id', $values['employee_status_id'])['code'] ?? null : null;

        if ($job === 'discharged' && ($values['discharge_date'] ?? null) === null) {
            $issues[] = self::issue('warning', 'field:discharge_date', __('imports.discharged_no_date'));
        }

        if ($status === 'active' && in_array($job, ['discharged', 'fled', 'retired'], true)) {
            $issues[] = self::issue('warning', 'field:employee_status_id', __('imports.active_but_left'));
        }
    }

    private function differs(string $key, mixed $old, mixed $new): string
    {
        return __('imports.differs', [
            'field' => FieldCatalogue::get($key)['label'],
            'stored' => $this->labels->display($key, $old) ?? '—',
            'file' => $this->labels->display($key, $new) ?? '—',
        ]);
    }

    // ── The registers ───────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<int, mixed>>  $parsed
     * @param  array<int, array{index: int, letter: string, title: string, target: array{0: string, 1: string|int|null}}>  $columns
     */
    private function records(ImportSheet $sheet, array $parsed, array $columns): void
    {
        $type = $sheet->recordType();
        $fields = RecordCatalogue::fields($type);
        $byIndex = array_column($columns, null, 'index');
        $rows = [];

        foreach ($parsed as $line => $cells) {
            $rows[$line] = $this->recordRow($type, $fields, $cells, $byIndex);
        }

        // The stored employees the rows name that the employees' sheet does not.
        $numbers = array_values(array_unique(array_filter(array_column($rows, 'personal_number'), fn ($n) => $n !== null && ! isset($this->fileRows[$n]))));
        $stored = [];

        foreach (array_chunk($numbers, 1000) as $chunk) {
            foreach (Employee::query()->whereIn('personal_number', $chunk)->get(['id', 'personal_number', 'file_status', 'first_name', 'father_name', 'family_name']) as $e) {
                $stored[$e->personal_number] = $e;
            }
        }

        // The records already kept for those employees, to leave out what is there.
        $ids = array_values(array_filter(array_merge(array_map(fn ($e) => $e->id, $stored), array_column($this->fileRows, 'employee_id'))));
        $kept = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($type->model()::query()->kept()->whereIn('employee_id', $chunk)->get() as $record) {
                $values = RecordValues::of($record);
                $kept[$record->employee_id][self::signature($type, $values)] = $record->id;

                if ($type === RecordType::Attendance) {
                    $kept[$record->employee_id]['month:'.$values['year'].'-'.$values['month']] = [$record->id, $values];
                }
            }
        }

        $seen = [];
        $months = [];

        foreach ($rows as $line => $r) {
            if ($type === RecordType::Attendance && $r['employee_key'] !== null && isset($r['values']['year'], $r['values']['month'])) {
                $months[$r['employee_key'].'|'.$r['values']['year'].'-'.$r['values']['month']][] = $line;
            }
        }

        $labels = new RecordLabels;

        foreach ($rows as $line => $r) {
            $issues = $r['issues'];
            $values = $r['values'];
            $number = $r['personal_number'];
            $employeeId = null;
            $action = 'add';
            $recordId = null;
            $changes = null;
            $name = null;

            if ($number === null) {
                $issues[] = self::issue('error', 'personal_number', __('imports.personal_number_missing'));
            } elseif (isset($this->fileRows[$number])) {
                $file = $this->fileRows[$number];

                if ($file['status'] === RowStatus::Invalid) {
                    $issues[] = self::issue('error', 'personal_number', __('imports.employee_invalid', ['number' => $number, 'line' => $file['line']]));
                }

                $employeeId = $file['employee_id'];
                $name = $file['name'];
            } elseif (isset($stored[$number])) {
                if (! $stored[$number]->isActive()) {
                    $issues[] = self::issue('error', 'personal_number', __('imports.employee_not_active', ['number' => $number]));
                }

                $employeeId = $stored[$number]->id;
                $name = ArabicText::searchKey((string) $stored[$number]->fullName());
            } else {
                $issues[] = self::issue('error', 'personal_number', __('imports.employee_unknown', ['number' => $number]));
            }

            if ($r['name_check'] !== null && $name !== null && ArabicText::searchKey($r['name_check']) !== $name) {
                $issues[] = self::issue('warning', 'name_check', __('imports.name_differs', ['sheet_name' => $r['name_check'], 'file_name' => $this->fileName($number, $stored)]));
            }

            $clean = ! self::hasError($issues);

            if ($clean) {
                try {
                    RecordValues::ensureConsistent($type, $values, 'values', 0, false);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $issues[] = self::issue('error', 'field:'.substr($field, 7), $messages[0]);
                    }
                }
            }

            if ($type === RecordType::Movements) {
                $this->movementNotes($values, $r['typed_days'], $issues);
            }

            $mine = $kept[$employeeId] ?? [];
            $signature = self::signature($type, $values);

            if ($type === RecordType::Attendance && isset($values['year'], $values['month'])) {
                $month = sprintf('%04d-%02d', $values['year'], $values['month']);
                $sameLines = $months[$r['employee_key'].'|'.$values['year'].'-'.$values['month']] ?? [];

                if (count($sameLines) > 1) {
                    $issues[] = self::issue('error', 'field:month', __('imports.month_twice', ['month' => $month, 'number' => $number, 'lines' => implode('، ', $sameLines)]));
                } elseif (isset($mine['month:'.$values['year'].'-'.$values['month']])) {
                    [$storedId, $storedValues] = $mine['month:'.$values['year'].'-'.$values['month']];
                    $diff = array_filter(array_keys($values), fn ($k) => ($storedValues[$k] ?? null) !== $values[$k]);

                    if ($diff === []) {
                        $action = 'skip';
                        $issues[] = self::issue('info', null, __('imports.record_exists'));
                    } elseif ($this->duplicates === Duplicates::Skip) {
                        $action = 'skip';
                        $issues[] = self::issue('info', null, __('imports.month_exists_skipped', ['month' => $month]));

                        foreach ($diff as $k) {
                            $issues[] = self::issue('warning', 'field:'.$k, $this->recordDiffers($labels, $type, $k, $storedValues[$k] ?? null, $values[$k]));
                        }
                    } else {
                        $action = 'correct';
                        $recordId = $storedId;
                        $changes = [];

                        foreach ($diff as $k) {
                            $changes[$k] = ['from' => $storedValues[$k] ?? null, 'to' => $values[$k]];
                        }
                    }
                }
            } elseif (isset($mine[$signature])) {
                $action = 'skip';
                $issues[] = self::issue('info', null, __('imports.record_exists'));
            } elseif ($employeeId !== null || $number !== null) {
                $key = ($number ?? '').'|'.$signature;

                if (isset($seen[$key])) {
                    $issues[] = self::issue('warning', null, __('imports.record_same_in_file', ['line' => $seen[$key]]));
                } else {
                    $seen[$key] = $line;
                }
            }

            $status = self::status($issues, $action === 'skip');

            $this->rows[] = [
                'sheet' => $sheet->value,
                'line' => $line,
                'status' => $status->value,
                'action' => $action,
                'personal_number' => $number,
                'employee_id' => $employeeId,
                'record_id' => $recordId,
                'cells' => $r['cells'],
                'values' => $values,
                'changes' => $changes,
                'issues' => $issues,
            ];
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<int, mixed>  $cells
     * @param  array<int, array{index: int, letter: string, title: string, target: array{0: string, 1: string|int|null}}>  $columns
     * @return array<string, mixed>
     */
    private function recordRow(RecordType $type, array $fields, array $cells, array $columns): array
    {
        $values = [];
        $issues = [];
        $display = [];
        $number = null;
        $nameCheck = null;
        $typedDays = null;

        foreach ($cells as $index => $raw) {
            $column = $columns[$index];
            [$what, $which] = $column['target'];
            $text = ImportCell::text($raw);
            $display[$column['title']] = $text;

            switch ($what) {
                case ImportColumns::PERSONAL_NUMBER:
                    $number = ArabicText::digitsToAscii((string) $text);

                    break;
                case ImportColumns::NAME_CHECK:
                    $nameCheck = $text;

                    break;
                case ImportColumns::TYPED_DAYS:
                    $typedDays = is_numeric($raw) ? (float) $raw : (is_numeric(ArabicText::digitsToAscii((string) $text)) ? (float) ArabicText::digitsToAscii((string) $text) : null);

                    break;
                case ImportColumns::FIELD:
                    $field = $fields[$which];

                    if ($field['type'] === 'list') {
                        [$id, $error] = $this->lookups->item($field['list'], $text, $field['label']);
                        $error === null ? $values[$which] = $id : $issues[] = self::issue('error', 'field:'.$which, $error);
                    } elseif ($field['type'] === 'unit') {
                        [$id, $error] = $this->lookups->unit(array_combine(range(1, count($parts = array_map('trim', explode('/', $text)))), $parts), $field['label']);
                        $error === null ? $values[$which] = $id : $issues[] = self::issue('error', 'field:'.$which, $error);
                    } elseif ($field['type'] === 'date') {
                        $values[$which] = ImportCell::date($raw);
                    } else {
                        $values[$which] = $text;
                    }

                    break;
            }
        }

        try {
            $values = RecordValues::validate($type, $values, 'values', false);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $key = str_starts_with($field, 'values.') ? substr($field, 7) : null;
                $issues[] = self::issue('error', $key === null ? null : 'field:'.$key, $messages[0]);
                unset($values[$key]);
            }

            // What is right of the row still reads, for the messages; required ones missing stay out.
            $values = array_filter($values, fn ($v) => $v !== null);
        }

        return [
            'personal_number' => $number === '' ? null : $number,
            'employee_key' => $number,
            'name_check' => $nameCheck,
            'typed_days' => $typedDays,
            'values' => $values,
            'cells' => $display,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $issues
     */
    private function movementNotes(array $values, ?float $typed, array &$issues): void
    {
        $computed = isset($values['starts_on'], $values['ends_on'])
            ? CarbonImmutable::parse($values['starts_on'])->diffInDays(CarbonImmutable::parse($values['ends_on'])) + 1 : null;

        if ($typed !== null && $computed !== null && (int) round($typed) !== (int) $computed) {
            $issues[] = self::issue('warning', 'typed_days', __('imports.days_differ', ['typed' => self::number($typed), 'computed' => (int) $computed]));
        } elseif ($typed !== null && $computed === null) {
            $issues[] = self::issue('warning', 'typed_days', __('imports.days_without_period', ['typed' => self::number($typed)]));
        }

        $this->movementCodes ??= DB::table('movement_types')->whereNotNull('code')->pluck('code', 'id')->all();

        if (isset($values['movement_type_id']) && in_array($this->movementCodes[$values['movement_type_id']] ?? null, MovementEffects::codes(), true)) {
            $issues[] = self::issue('info', 'field:movement_type_id', __('imports.movement_history'));
        }
    }

    private function recordDiffers(RecordLabels $labels, RecordType $type, string $key, mixed $old, mixed $new): string
    {
        return __('imports.differs', [
            'field' => RecordCatalogue::label($type, $key),
            'stored' => $labels->prime($type, [[$key => $old]])->display($type, $key, $old) ?? '—',
            'file' => $labels->prime($type, [[$key => $new]])->display($type, $key, $new) ?? '—',
        ]);
    }

    /** @param array<string, Employee> $stored */
    private function fileName(?string $number, array $stored): string
    {
        return $number !== null && isset($stored[$number]) ? (string) $stored[$number]->fullName() : '—';
    }

    /** A record's values as one comparable key: the fields the workbook has, in the normal form. */
    private static function signature(RecordType $type, array $values): string
    {
        $keys = array_keys(array_filter(RecordCatalogue::fields($type), fn ($f, $k) => ! in_array($k, ['notes', 'new_org_unit_id', 'new_job_status_id', 'new_position_level_id', 'new_job_title_id'], true), ARRAY_FILTER_USE_BOTH));
        $out = [];

        foreach ($keys as $k) {
            $out[$k] = $values[$k] ?? null;
        }

        return md5(json_encode($out));
    }

    // ── Shared ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{level: string, field: ?string, message: string} */
    private static function issue(string $level, ?string $field, string $message): array
    {
        return ['level' => $level, 'field' => $field, 'message' => $message];
    }

    /** @param list<array{level: string, field: ?string, message: string}> $issues */
    private static function hasError(array $issues, ?string $field = null): bool
    {
        foreach ($issues as $issue) {
            if ($issue['level'] === 'error' && ($field === null || $issue['field'] === $field)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{level: string, field: ?string, message: string}> $issues */
    private static function status(array $issues, bool $skipped): RowStatus
    {
        return match (true) {
            self::hasError($issues) => RowStatus::Invalid,
            $skipped => RowStatus::Excluded,
            in_array('warning', array_column($issues, 'level'), true) => RowStatus::Review,
            default => RowStatus::Valid,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: array{total: int, valid: int, review: int, invalid: int, excluded: int}, sheets: array<string, array{total: int, valid: int, review: int, invalid: int, excluded: int}>}
     */
    public static function counts(array $rows): array
    {
        $empty = ['total' => 0, 'valid' => 0, 'review' => 0, 'invalid' => 0, 'excluded' => 0];
        $out = ['total' => $empty, 'sheets' => []];

        foreach ($rows as $row) {
            $out['sheets'][$row['sheet']] ??= $empty;
            $out['sheets'][$row['sheet']]['total']++;
            $out['sheets'][$row['sheet']][$row['status']]++;
            $out['total']['total']++;
            $out['total'][$row['status']]++;
        }

        return $out;
    }

    private static function number(float $value): string
    {
        return floor($value) === $value ? (string) (int) $value : (string) $value;
    }
}
