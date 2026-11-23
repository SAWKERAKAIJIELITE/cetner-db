<?php

namespace App\Modules\Settings\Models;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A unit of the organisational structure, at level 1 to 6 (D-026).
 *
 * @property int $id
 * @property ?int $parent_id
 * @property int $level
 * @property string $name
 * @property string $search_key
 * @property ?string $created_by
 * @property ?Carbon $archived_at
 * @property ?string $archived_by
 */
class OrgUnit extends Model
{
    public const MAX_LEVEL = 6;

    protected $fillable = ['name'];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OrgUnit $unit) {
            $unit->name = trim(preg_replace('/\s+/u', ' ', $unit->name) ?? $unit->name);
            $unit->search_key = ArabicText::searchKey($unit->name);
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
