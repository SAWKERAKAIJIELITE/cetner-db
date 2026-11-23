<?php

namespace App\Modules\Settings\Models;

use App\Modules\Settings\Support\ListCatalogue;
use App\Support\ArabicText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An item of a managed list. The 27 lists have the same shape and one table each (D-024); this one model
 * serves them all: `ListItem::queryFor('gender')` reads the table `genders`.
 * Archived, never deleted: the files that use an item keep showing it (D-007).
 *
 * @property int $id
 * @property string $label
 * @property string $search_key
 * @property ?string $code
 * @property int $sort_order
 * @property ?string $created_by
 * @property ?Carbon $archived_at
 * @property ?string $archived_by
 */
class ListItem extends Model
{
    /**
     * Only so that tools which read a model's columns (the API documentation) find a table of this shape.
     * Code never relies on it: every read and write goes through forList() or queryFor(), which set the list's table.
     */
    protected $table = 'genders';

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
        static::saving(function (ListItem $item) {
            $item->label = self::clean($item->label);
            $item->search_key = ArabicText::searchKey($item->label);
        });
    }

    /** A new, unsaved item of the list. */
    public static function forList(string $key): static
    {
        $item = new static;
        $item->setTable(ListCatalogue::table($key));

        return $item;
    }

    /** @return Builder<static> */
    public static function queryFor(string $key): Builder
    {
        return static::forList($key)->newQuery();
    }

    public static function clean(string $label): string
    {
        return trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
    }

    public function listKey(): ?string
    {
        return ListCatalogue::keyOfTable($this->getTable());
    }

    public function isSystem(): bool
    {
        return $this->code !== null;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
