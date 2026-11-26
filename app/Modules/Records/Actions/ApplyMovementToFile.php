<?php

namespace App\Modules\Records\Actions;

use App\Modules\Employees\Actions\SubmitEmployeeChanges;
use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Records\Support\MovementEffects;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/**
 * An approved movement updates the employee's file (D-048), once, when its date has come: approved for today or
 * earlier, at once; for a later day, by `records:apply-due-movements` that morning. The file takes only the fields
 * that differ, as a change «تطبيق حركة» in its history, by the movement's reviewer. A later movement already taken
 * wins: an old movement entered late does not undo it. A removed or corrected movement does not undo what the file
 * took (the file is corrected as any file).
 */
final class ApplyMovementToFile
{
    public function __construct(private readonly SubmitEmployeeChanges $changes) {}

    /** @return bool whether it was taken now */
    public function handle(EmployeeMovement $movement, ?StaffUser $actor = null): bool
    {
        return DB::transaction(function () use ($movement, $actor) {
            $movement = EmployeeMovement::query()->lockForUpdate()->findOrFail($movement->getKey());

            if ($movement->isDeleted() || $movement->getAttribute('applied_to_file_at') !== null
                || $movement->getAttribute('occurred_on')->format('Y-m-d') > BusinessDay::today()->format('Y-m-d')) {
                return false;
            }

            $effects = MovementEffects::of($movement);

            if ($effects === []) {
                return false;
            }

            $employee = Employee::query()->findOrFail($movement->employee_id);
            $effects = array_diff_key($effects, array_flip($this->takenByLater($movement)));
            $actor ??= StaffUser::query()->findOrFail($movement->approved_by);
            $set = null;

            if ($effects !== [] && $employee->isActive()) {
                $type = DB::table('movement_types')->where('id', $movement->getAttribute('movement_type_id'))->value('label');
                $set = $this->changes->fromMovement($employee, $effects, "حركة: {$type} ({$movement->getAttribute('occurred_on')->format('Y-m-d')})", $actor);
            }

            $movement->forceFill(['applied_to_file_at' => now(), 'file_change_set_id' => $set?->getKey()])->save();

            return true;
        });
    }

    /**
     * The file fields that a later movement of this employee, already taken, set.
     *
     * @return list<string>
     */
    private function takenByLater(EmployeeMovement $movement): array
    {
        return EmployeeMovement::query()->kept()
            ->where('employee_id', $movement->employee_id)
            ->whereKeyNot($movement->getKey())
            ->whereNotNull('applied_to_file_at')
            ->where('occurred_on', '>', $movement->getAttribute('occurred_on')->format('Y-m-d'))
            ->get()
            ->flatMap(fn (EmployeeMovement $later) => array_keys(MovementEffects::of($later)))
            ->unique()->values()->all();
    }
}
