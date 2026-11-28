<?php

namespace App\Modules\Imports\Http\Resources;

use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Imports\Enums\ImportSheet;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staged row: its line in the workbook, what the import does with it, the cells as read and what is wrong.
 *
 * @mixin ImportRow
 */
class ImportRowResource extends JsonResource
{
    /** Set for each page by the controller. */
    public static ?ValueLabels $labels = null;

    public static ?RecordLabels $recordLabels = null;

    private const ACTIONS = [
        'create' => 'ملف جديد',
        'update' => 'تحديث ملف محفوظ',
        'add' => 'سجل جديد',
        'correct' => 'تصحيح سجل محفوظ',
        'skip' => 'لا شيء',
    ];

    public function toArray(Request $request): array
    {
        self::$labels ??= new ValueLabels;
        self::$recordLabels ??= new RecordLabels;
        $type = $this->sheet->recordType();

        return [
            'id' => $this->id,
            /** employees, movements, attendance, conduct, debts */
            'sheet' => $this->sheet->value,
            'sheet_label' => $this->sheet->label(),
            /** The line in the workbook, as Excel numbers it. */
            'line' => $this->line,
            /** valid, review, invalid, excluded */
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            /** create, update (a file); add, correct (a record); skip */
            'action' => $this->action,
            'action_label' => self::ACTIONS[$this->action],
            'personal_number' => $this->personal_number,
            /** The stored file it updates or names; once written, the file it made. */
            'employee_id' => $this->employee_id,
            /** Once written, the record it made or corrected. */
            'record_id' => $this->record_id,
            /**
             * The row as read: column title => text.
             *
             * @var array<string, string|null>
             */
            'cells' => (object) $this->cells,
            /**
             * What it changes in a stored file or month.
             *
             * @var list<array{field: string, label: string, from: string|null, to: string|null}>
             */
            'changes' => collect($this->changes ?? [])->map(fn ($c, $key) => [
                'field' => $key,
                'label' => $this->sheet === ImportSheet::Employees ? FieldCatalogue::get($key)['label'] : RecordCatalogue::label($type, $key),
                'from' => $this->display($key, $c['from']),
                'to' => $this->display($key, $c['to']),
            ])->values()->all(),
            /**
             * What is wrong (error), deserves a look (warning) or is worth knowing (info); `field` is the column's target
             * (`field:national_number`, `org_level:1` …), as the sheet's columns name it.
             */
            'issues' => $this->issues,
        ];
    }

    private function display(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($this->sheet === ImportSheet::Employees) {
            return self::$labels->display($key, $value);
        }

        $type = $this->sheet->recordType();

        return self::$recordLabels->prime($type, [[$key => $value]])->display($type, $key, $value);
    }
}
