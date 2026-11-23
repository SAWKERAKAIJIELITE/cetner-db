<?php

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Models\OrgUnit;
use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The organisational structure (D-026): units added under a parent (six levels at most), renamed,
 * archived (once their children are) and restored (under an active parent). A unit is not moved.
 */
final class ManageOrgUnits
{
    public function add(string $name, ?OrgUnit $parent, StaffUser $actor): OrgUnit
    {
        return DB::transaction(function () use ($name, $parent, $actor) {
            $level = 1;

            if ($parent !== null) {
                $parent = OrgUnit::query()->lockForUpdate()->findOrFail($parent->getKey());

                if ($parent->isArchived()) {
                    throw ValidationException::withMessages(['parent_id' => __('settings.org_parent_archived')]);
                }

                if ($parent->level >= OrgUnit::MAX_LEVEL) {
                    throw ValidationException::withMessages(['parent_id' => __('settings.org_max_depth', ['max' => OrgUnit::MAX_LEVEL])]);
                }

                $level = $parent->level + 1;
            } else {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['org_units.roots']);
            }

            $this->refuseDuplicate($name, $parent?->getKey());

            $unit = new OrgUnit(['name' => $name]);
            $unit->forceFill([
                'parent_id' => $parent?->getKey(),
                'level' => $level,
                'created_by' => $actor->getKey(),
            ])->save();

            AuditTrail::record('settings.org_unit_added', $actor, [
                'org_unit_id' => $unit->getKey(),
                'parent_id' => $parent?->getKey(),
                'name' => $unit->name,
                'level' => $level,
            ]);

            return $unit;
        });
    }

    public function rename(OrgUnit $unit, string $name, StaffUser $actor): OrgUnit
    {
        return DB::transaction(function () use ($unit, $name, $actor) {
            $unit = OrgUnit::query()->lockForUpdate()->findOrFail($unit->getKey());
            $from = $unit->name;
            $this->refuseDuplicate($name, $unit->parent_id, $unit);
            $unit->name = $name;

            if ($unit->isDirty('name')) {
                $unit->save();

                AuditTrail::record('settings.org_unit_renamed', $actor, [
                    'org_unit_id' => $unit->getKey(),
                    'from' => $from,
                    'to' => $unit->name,
                ]);
            }

            return $unit;
        });
    }

    public function archive(OrgUnit $unit, StaffUser $actor): OrgUnit
    {
        return DB::transaction(function () use ($unit, $actor) {
            $unit = OrgUnit::query()->lockForUpdate()->findOrFail($unit->getKey());

            if ($unit->isArchived()) {
                throw ValidationException::withMessages(['unit' => __('settings.already_archived')]);
            }

            if ($unit->children()->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['unit' => __('settings.org_has_active_children')]);
            }

            // Step 3 adds: no active employee in the unit.
            $unit->forceFill(['archived_at' => now(), 'archived_by' => $actor->getKey()])->save();

            AuditTrail::record('settings.org_unit_archived', $actor, ['org_unit_id' => $unit->getKey(), 'name' => $unit->name]);

            return $unit;
        });
    }

    public function restore(OrgUnit $unit, StaffUser $actor): OrgUnit
    {
        return DB::transaction(function () use ($unit, $actor) {
            $unit = OrgUnit::query()->lockForUpdate()->findOrFail($unit->getKey());

            if (! $unit->isArchived()) {
                throw ValidationException::withMessages(['unit' => __('settings.not_archived')]);
            }

            if ($unit->parent !== null && $unit->parent->isArchived()) {
                throw ValidationException::withMessages(['unit' => __('settings.org_parent_archived')]);
            }

            $unit->forceFill(['archived_at' => null, 'archived_by' => null])->save();

            AuditTrail::record('settings.org_unit_restored', $actor, ['org_unit_id' => $unit->getKey(), 'name' => $unit->name]);

            return $unit;
        });
    }

    private function refuseDuplicate(string $name, ?int $parentId, ?OrgUnit $except = null): void
    {
        $key = ArabicText::searchKey(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));

        $existing = OrgUnit::query()
            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId), fn ($q) => $q->whereNull('parent_id'))
            ->where('search_key', $key)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages(['name' => $existing->isArchived()
                ? __('settings.org_duplicate_archived', ['name' => $existing->name])
                : __('settings.org_duplicate', ['name' => $existing->name])]);
        }
    }
}
