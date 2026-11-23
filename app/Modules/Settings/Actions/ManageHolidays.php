<?php

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Models\Holiday;
use Illuminate\Support\Facades\DB;

/** «العطل الرسمية» (D-027): added, corrected and removed, each audited with its dates. */
final class ManageHolidays
{
    /** @param array{name: string, starts_on: string, ends_on: string, notes?: ?string} $data */
    public function add(array $data, StaffUser $actor): Holiday
    {
        return DB::transaction(function () use ($data, $actor) {
            $holiday = new Holiday($this->clean($data));
            $holiday->forceFill(['created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()])->save();
            $holiday->refresh();

            AuditTrail::record('settings.holiday_added', $actor, ['holiday_id' => $holiday->getKey()] + $this->describe($holiday));

            return $holiday;
        });
    }

    /** @param array{name?: string, starts_on?: string, ends_on?: string, notes?: ?string} $data */
    public function update(Holiday $holiday, array $data, StaffUser $actor): Holiday
    {
        return DB::transaction(function () use ($holiday, $data, $actor) {
            $holiday = Holiday::query()->lockForUpdate()->findOrFail($holiday->getKey());
            $before = $this->describe($holiday);
            $holiday->fill($this->clean($data));

            if ($holiday->isDirty()) {
                $holiday->forceFill(['updated_by' => $actor->getKey()])->save();
                $holiday->refresh();

                AuditTrail::record('settings.holiday_updated', $actor, [
                    'holiday_id' => $holiday->getKey(),
                    'from' => $before,
                    'to' => $this->describe($holiday),
                ]);
            }

            return $holiday;
        });
    }

    public function delete(Holiday $holiday, StaffUser $actor): void
    {
        DB::transaction(function () use ($holiday, $actor) {
            $holiday = Holiday::query()->lockForUpdate()->findOrFail($holiday->getKey());
            $described = $this->describe($holiday);
            $holiday->delete();

            AuditTrail::record('settings.holiday_deleted', $actor, ['holiday_id' => $holiday->getKey()] + $described);
        });
    }

    private function clean(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name']);
        }

        if (array_key_exists('notes', $data)) {
            $data['notes'] = $data['notes'] === null || trim($data['notes']) === '' ? null : trim($data['notes']);
        }

        return $data;
    }

    private function describe(Holiday $holiday): array
    {
        return [
            'name' => $holiday->name,
            'starts_on' => $holiday->starts_on?->format('Y-m-d'),
            'ends_on' => $holiday->ends_on?->format('Y-m-d'),
        ];
    }
}
