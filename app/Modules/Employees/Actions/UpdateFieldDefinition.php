<?php

namespace App\Modules\Employees\Actions;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Enums\FileStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\FieldDefinition;
use App\Modules\Employees\Support\EmployeeDerived;
use App\Modules\Employees\Support\FieldDefinitions;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Support\Facades\DB;

/**
 * The settings managers change whether a field counts for completeness (the workbook's ✔) or its cycle
 * (D-032). Every active file's completeness is recomputed in the same transaction, so lists never show a
 * percentage from the old definition.
 */
final class UpdateFieldDefinition
{
    public function handle(FieldDefinition $definition, ?bool $counts, ?Cycle $cycle, StaffUser $actor): FieldDefinition
    {
        return DB::transaction(function () use ($definition, $counts, $cycle, $actor) {
            $definition = FieldDefinition::query()->lockForUpdate()->findOrFail($definition->getKey());
            $before = ['counts_for_completeness' => $definition->counts_for_completeness, 'cycle' => $definition->cycle->value];

            if ($counts !== null) {
                $definition->counts_for_completeness = $counts;
            }

            if ($cycle !== null) {
                $definition->cycle = $cycle;
            }

            if (! $definition->isDirty()) {
                return $definition;
            }

            $definition->forceFill(['updated_by' => $actor->getKey()])->save();
            FieldDefinitions::forget();
            $files = self::recomputeAll();

            AuditTrail::record('employees.field_definition_updated', $actor, [
                'field' => $definition->key,
                'from' => $before,
                'to' => ['counts_for_completeness' => $definition->counts_for_completeness, 'cycle' => $definition->cycle->value],
                'files_recomputed' => $files,
            ]);

            return $definition;
        });
    }

    /** @return int the files recomputed */
    public static function recomputeAll(): int
    {
        $count = 0;

        Employee::query()->where('file_status', FileStatus::Active)->orderBy('id')->chunkById(500, function ($employees) use (&$count) {
            foreach ($employees as $employee) {
                EmployeeDerived::refresh($employee);

                if ($employee->isDirty()) {
                    $employee->saveQuietly();
                }

                $count++;
            }
        });

        return $count;
    }
}
