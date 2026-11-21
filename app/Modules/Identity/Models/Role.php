<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $label_ar
 */
class Role extends Model
{
    protected $fillable = ['slug', 'label_ar'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class)->orderBy('slug');
    }

    public function users(): HasMany
    {
        return $this->hasMany(StaffUser::class);
    }

    public function enum(): ?StaffRole
    {
        return StaffRole::tryFrom($this->slug);
    }

    /** The administrator's role holds every permission and stores none. */
    public function isAdministrator(): bool
    {
        return $this->slug === StaffRole::SystemAdmin->value;
    }

    /** The general manager's role takes read-only permissions only. */
    public function isReadOnly(): bool
    {
        return $this->slug === StaffRole::GeneralManager->value;
    }

    /** @return list<string> */
    public function permissionSlugs(): array
    {
        if ($this->isAdministrator()) {
            return Permission::query()->orderBy('slug')->pluck('slug')->all();
        }

        return $this->permissions->pluck('slug')->values()->all();
    }
}
