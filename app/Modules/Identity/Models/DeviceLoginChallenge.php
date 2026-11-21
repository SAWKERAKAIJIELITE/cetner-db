<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $staff_device_id
 * @property string $challenge
 * @property Carbon $expires_at
 * @property ?Carbon $used_at
 */
class DeviceLoginChallenge extends Model
{
    use HasUuids;
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $fillable = ['staff_device_id', 'challenge', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** Expired for a day: `php artisan model:prune` removes them. */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }
}
