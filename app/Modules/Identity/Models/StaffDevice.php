<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A computer that may log in (D-013). The key pair is the proof; the MAC address is a label.
 *
 * @property string $id
 * @property string $label
 * @property string $public_key
 * @property string $fingerprint
 * @property string $reported_mac
 * @property string $os_info
 * @property DeviceStatus $status
 * @property ?string $staff_user_id
 * @property ?string $approved_by
 * @property ?Carbon $approved_at
 * @property ?string $revoked_by
 * @property ?Carbon $revoked_at
 * @property ?string $revoke_reason
 * @property ?Carbon $last_seen_at
 * @property Carbon $created_at
 */
class StaffDevice extends Model
{
    use HasUuids;

    /** What the device sends; status, owner and approval are set by the Actions that own them (D-016). */
    protected $fillable = ['label', 'public_key', 'fingerprint', 'reported_mac', 'os_info'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'staff_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'approved_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'revoked_by');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(AccessToken::class, 'staff_device_id');
    }

    public function isApproved(): bool
    {
        return $this->status === DeviceStatus::Approved;
    }
}
