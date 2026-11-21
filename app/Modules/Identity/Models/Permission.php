<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $label_ar
 * @property bool $read_only
 */
class Permission extends Model
{
    protected $fillable = ['slug', 'label_ar', 'read_only'];

    protected function casts(): array
    {
        return ['read_only' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
