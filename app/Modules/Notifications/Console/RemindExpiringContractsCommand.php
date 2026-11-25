<?php

namespace App\Modules\Notifications\Console;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Notifications\Notifications\ContractEndingSoon;
use App\Support\BusinessDay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every morning: each approved file whose contract ends within 30 days, not told yet for that end date, is told to
 * every active staff user who updates files (`employees.update`: data entry and the supervisor), not the
 * administrator or the general manager, as the hiring system does (D-046).
 */
class RemindExpiringContractsCommand extends Command
{
    public const WINDOW_DAYS = 30;

    protected $signature = 'employees:remind-expiring-contracts';

    protected $description = 'Notify the staff who update files of contracts ending within 30 days';

    public function handle(): int
    {
        $today = BusinessDay::today();
        $recipients = StaffUser::query()->where('is_active', true)->with('role')->get()
            ->filter(fn (StaffUser $u) => $u->hasPermission('employees.update') && ! in_array($u->role?->slug, ['system_admin', 'general_manager'], true));

        $told = 0;

        Employee::query()->where('file_status', 'active')
            ->whereBetween('contract_ends_on', [$today->format('Y-m-d'), $today->addDays(self::WINDOW_DAYS)->format('Y-m-d')])
            ->orderBy('contract_ends_on')
            ->each(function (Employee $employee) use ($recipients, $today, &$told) {
                $endsOn = $employee->contract_ends_on->format('Y-m-d');
                $already = DB::table('notifications')->where('type', ContractEndingSoon::class)
                    ->where('data->details->employee_id', $employee->id)->where('data->details->ends_on', $endsOn)->exists();

                if ($already) {
                    return;
                }

                $notice = new ContractEndingSoon($employee->id, $employee->personal_number, $employee->fullName(), $endsOn,
                    (int) $today->diffInDays($employee->contract_ends_on));

                foreach ($recipients as $user) {
                    $user->notify($notice);
                }

                $told++;
            });

        $this->info("{$told} contract(s) told.");

        return self::SUCCESS;
    }
}
