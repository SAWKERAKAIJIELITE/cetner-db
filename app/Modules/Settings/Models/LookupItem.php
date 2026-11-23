<?php

namespace App\Modules\Settings\Models;

use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An item of a list. Archived, never deleted: the files that use it keep showing it (D-007, D-024).
 *
 * @property int $id
 * @property int $lookup_list_id
 * @property string $label
 * @property string $search_key
 * @property ?string $code
 * @property int $sort_order
 * @property ?string $created_by
 * @property ?Carbon $archived_at
 * @property ?string $archived_by
 */
class LookupItem extends Model
{
    /** The label; the search key follows it. Code, order and archiving are set by the Actions (D-016). */
    protected $fillable = ['label'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LookupItem $item) {
            $item->label = trim(preg_replace('/\s+/u', ' ', $item->label) ?? $item->label);
            $item->search_key = ArabicText::searchKey($item->label);
        });
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(LookupList::class, 'lookup_list_id');
    }

    public function isSystem(): bool
    {
        return $this->code !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
