<?php

namespace App\Modules\Settings\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Models\WorkSettings;
use Illuminate\Support\Facades\DB;

/** «إعدادات الفرع» (D-028): the fields sent change; the audit keeps each change's before and after. */
final class UpdateWorkSettings
{
    public function handle(array $data, StaffUser $actor): WorkSettings
    {
        return DB::transaction(function () use ($data, $actor) {
            $settings = WorkSettings::query()->lockForUpdate()->findOrFail(1);
            $settings->fill(array_intersect_key($data, array_flip(WorkSettings::EDITABLE)));

            $changes = [];

            foreach ($settings->getDirty() as $field => $value) {
                $before = $settings->getOriginal($field);

                // "8" and "8.00", "08:00" and "08:00:00" are no change.
                if ($this->same($field, $before, $value)) {
                    $settings->setAttribute($field, $before);

                    continue;
                }

                $changes[$field] = ['from' => $before, 'to' => $value];
            }

            if ($changes !== []) {
                $settings->forceFill(['updated_by' => $actor->getKey()])->save();
                AuditTrail::record('settings.work_updated', $actor, ['changes' => $changes]);
            }

            return $settings->refresh();
        });
    }

    private function same(string $field, mixed $before, mixed $after): bool
    {
        if (in_array($field, ['starts_at', 'ends_at'], true)) {
            return substr((string) $before, 0, 5) === substr((string) $after, 0, 5);
        }

        if (is_numeric($before) && is_numeric($after)) {
            return (float) $before === (float) $after;
        }

        return (string) $before === (string) $after;
    }
}
