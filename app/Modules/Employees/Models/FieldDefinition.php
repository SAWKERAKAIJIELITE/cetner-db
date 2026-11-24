<?php

namespace App\Modules\Employees\Models;

use App\Modules\Employees\Enums\Cycle;
use Illuminate\Database\Eloquent\Model;

/**
 * A field of «دليل الحقول» as data (D-032). `counts_for_completeness` and `cycle` are the settings
 * managers'; the rest follows FieldCatalogue on every seeding.
 *
 * @property string $key
 * @property string $label_ar
 * @property string $section_ar
 * @property string $workbook_column
 * @property string $type
 * @property ?string $list_key
 * @property Cycle $cycle
 * @property bool $counts_for_completeness
 * @property bool $identity
 * @property int $sort_order
 * @property ?string $updated_by
 */
class FieldDefinition extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'cycle' => Cycle::class,
            'counts_for_completeness' => 'boolean',
            'identity' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
