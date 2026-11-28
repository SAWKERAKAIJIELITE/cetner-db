<?php

namespace App\Modules\Imports\Models;

use App\Modules\Imports\Enums\ImportSheet;
use App\Modules\Imports\Enums\RowStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * One staged row of an import: as read, what it gives, what is wrong with it.
 *
 * @property int $id
 * @property string $import_id
 * @property ImportSheet $sheet
 * @property int $line
 * @property RowStatus $status
 * @property string $action
 * @property ?string $personal_number
 * @property ?string $employee_id
 * @property ?string $record_id
 * @property array<string, ?string> $cells
 * @property array<string, mixed> $values
 * @property ?array<string, array{0: mixed, 1: mixed}> $changes
 * @property list<array{level: string, field: ?string, message: string}> $issues
 */
class ImportRow extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'sheet' => ImportSheet::class,
            'status' => RowStatus::class,
            'cells' => 'array',
            'values' => 'array',
            'changes' => 'array',
            'issues' => 'array',
            'line' => 'integer',
        ];
    }
}
