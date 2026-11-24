<?php

namespace App\Modules\Employees\Console;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\IdentityLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Checks every locked identity against its fingerprint (D-038). A mismatch means the identity was changed
 * around the application and the database trigger (someone with database rights), and is reported loudly.
 * Scheduled daily (routes/console.php).
 */
class VerifyIdentityCommand extends Command
{
    protected $signature = 'employees:verify-identity';

    protected $description = 'Check every locked identity against its fingerprint';

    public function handle(): int
    {
        $checked = 0;
        $broken = [];

        Employee::query()->whereNotNull('identity_locked_at')->orderBy('id')->chunkById(500, function ($employees) use (&$checked, &$broken) {
            foreach ($employees as $employee) {
                $checked++;

                if (! IdentityLock::intact($employee)) {
                    $broken[] = $employee->id;
                }
            }
        });

        if ($broken === []) {
            $this->info("{$checked} locked identities checked: all intact.");

            return self::SUCCESS;
        }

        Log::critical('employees.identity_fingerprint_mismatch', ['employee_ids' => $broken]);
        AuditTrail::record('employees.identity_check_failed', null, ['employee_ids' => $broken, 'checked' => $checked]);

        $this->error(count($broken)." of {$checked} locked identities do not match their fingerprint:");

        foreach ($broken as $id) {
            $this->line('  '.$id);
        }

        return self::FAILURE;
    }
}
