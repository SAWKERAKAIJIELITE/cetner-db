<?php

namespace App\Modules\Audit\Models;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $event
 * @property string $category
 * @property ?string $actor_id
 * @property array $context
 * @property ?string $ip
 * @property Carbon $created_at
 */
class AuditEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Written by AuditTrail only. */
    protected $fillable = ['event', 'category', 'actor_id', 'context', 'ip'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'actor_id');
    }
}
