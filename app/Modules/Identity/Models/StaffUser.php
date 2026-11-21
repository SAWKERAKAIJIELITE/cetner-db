<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Support\Username;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A staff user: a data entry employee, the general manager or the system administrator (D-006).
 *
 * @property string $id
 * @property string $personal_number
 * @property string $username
 * @property string $first_name
 * @property string $last_name
 * @property bool $is_active
 * @property ?Carbon $last_login_at
 * @property ?Carbon $deactivated_at
 * @property ?string $deactivated_by
 * @property ?string $deactivation_reason
 * @property ?int $role_id
 * @property ?string $role_assigned_by
 * @property ?Carbon $role_assigned_at
 */
class StaffUser extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;

    /** Caller-supplied columns only; activation and the role are set by Actions with forceFill (D-016). */
    protected $fillable = ['personal_number', 'username', 'first_name', 'last_name', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string>|null cached for the instance (one request) */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'role_assigned_at' => 'datetime',
        ];
    }

    protected function username(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Username::normalize($value));
    }

    // No remember-me tokens: the API is stateless.
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(StaffDevice::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return list<string> the role's permissions; every permission for the administrator; none without a role */
    public function permissionSlugs(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        return $this->permissionCache = $this->role?->permissionSlugs() ?? [];
    }

    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->permissionSlugs(), true);
    }

    public function refresh(): static
    {
        $this->permissionCache = null;

        return parent::refresh();
    }

    public function forgetPermissions(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('role');
    }
}
