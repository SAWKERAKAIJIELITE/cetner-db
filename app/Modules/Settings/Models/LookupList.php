<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of list (D-024). Created by the catalogue only; routes find it by `key`.
 *
 * @property int $id
 * @property string $key
 * @property string $label_ar
 * @property ?string $description_ar
 */
class LookupList extends Model
{
    protected $fillable = ['key', 'label_ar', 'description_ar'];

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    public function items(): HasMany
    {
        return $this->hasMany(LookupItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
