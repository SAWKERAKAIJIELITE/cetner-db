<?php

namespace App\Modules\Records\Console;

use App\Modules\Records\Actions\ApplyMovementToFile;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Records\Support\MovementEffects;
use App\Support\BusinessDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Every morning: approved movements whose day has come update their files (D-048), oldest first. */
class ApplyDueMovementsCommand extends Command
{
    protected $signature = 'records:apply-due-movements';

    protected $description = 'Apply to the employee files the approved movements whose date has come';

    public function handle(ApplyMovementToFile $apply): int
    {
        $types = DB::table('movement_types')->whereIn('code', MovementEffects::codes())->pluck('id')->all();
        $taken = 0;

        EmployeeMovement::query()->kept()->whereNull('applied_to_file_at')
            ->whereIn('movement_type_id', $types)
            ->where('occurred_on', '<=', BusinessDay::today()->format('Y-m-d'))
            ->orderBy('occurred_on')->orderBy('created_at')
            ->each(function (EmployeeMovement $movement) use ($apply, &$taken) {
                $taken += $apply->handle($movement) ? 1 : 0;
            });

        $this->info("{$taken} movement(s) taken by their files.");

        return self::SUCCESS;
    }
}
