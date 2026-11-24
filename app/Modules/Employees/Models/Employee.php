<?php

namespace App\Modules\Employees\Models;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\FileStatus;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Models\OrgUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The employee's file: the approved values of the 125 fields (D-032). Nothing writes a field but
 * ApplyChangeSet; the changes waiting for review are in change sets (D-033).
 *
 * @property string $id
 * @property FileStatus $file_status
 * @property ?string $search_name
 * @property string $completeness
 * @property array<string, list<string>> $missing_fields
 * @property ?string $created_by
 * @property ?Carbon $activated_at
 * @property ?Carbon $identity_locked_at
 * @property ?string $identity_locked_by
 * @property ?string $identity_hash
 * @property ?string $first_name
 * @property ?string $father_name
 * @property ?string $family_name
 * @property ?string $nickname
 * @property ?string $personal_number
 * @property ?string $national_number
 * @property ?int $org_unit_id
 * @property ?int $current_work_unit_id
 * @property ?Carbon $birth_date
 * @property ?Carbon $contract_starts_on
 * @property ?Carbon $contract_ends_on
 * @property ?Carbon $discharge_date
 * @property ?int $employee_status_id
 * @property ?int $job_status_id
 */
class Employee extends Model
{
    use HasUuids;

    /** No field is mass-assigned: ApplyChangeSet sets them one by one with forceFill (D-016). */
    protected $fillable = [];

    protected $attributes = ['file_status' => 'pending', 'missing_fields' => '{}', 'completeness' => 0];

    protected function casts(): array
    {
        $casts = [
            'file_status' => FileStatus::class,
            'missing_fields' => 'array',
            'activated_at' => 'datetime',
            'identity_locked_at' => 'datetime',
        ];

        foreach (FieldCatalogue::FIELDS as $key => $field) {
            $casts[$key] = match ($field['type']) {
                'date' => 'date:Y-m-d',
                'bool' => 'boolean',
                'int', 'year', 'list', 'org_unit' => 'integer',
                default => null,
            };
        }

        return array_filter($casts);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function identityLocker(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'identity_locked_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by');
    }

    public function changeSets(): HasMany
    {
        return $this->hasMany(EmployeeChangeSet::class);
    }

    public function pendingChangeSets(): HasMany
    {
        return $this->changeSets()->where('status', ChangeSetStatus::Pending)->orderBy('submitted_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(EmployeeReview::class);
    }

    public function fullName(): ?string
    {
        $name = trim(implode(' ', array_filter([$this->first_name, $this->father_name, $this->family_name])));

        return $name === '' ? null : $name;
    }

    public function isActive(): bool
    {
        return $this->file_status === FileStatus::Active;
    }
}
