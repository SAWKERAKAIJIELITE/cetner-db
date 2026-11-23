<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An official holiday, both ends included (D-027). `days` is computed by the database.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property int $days
 * @property ?string $notes
 * @property ?string $created_by
 * @property ?string $updated_by
 */
class Holiday extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on', 'notes'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'days' => 'integer',
        ];
    }
}
