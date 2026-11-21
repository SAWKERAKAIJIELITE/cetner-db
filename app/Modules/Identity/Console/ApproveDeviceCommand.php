<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\ApproveDevice;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Identity\Support\Username;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/** Bootstrap and break-glass: approve a device from the server's shell (D-018). */
class ApproveDeviceCommand extends Command
{
    protected $signature = 'staff:approve-device {device : The device id} {username : Its one owner} {--fingerprint= : As shown on the device}';

    protected $description = 'Approve a pending staff device for its owner (the first administrator, or when no administrator can log in)';

    public function handle(ApproveDevice $action): int
    {
        $device = StaffDevice::query()->find($this->argument('device'));
        $owner = StaffUser::query()->where('username', Username::normalize($this->argument('username')))->first();
        $fingerprint = (string) ($this->option('fingerprint') ?? $this->ask('Fingerprint shown on the device'));

        if ($device === null || $owner === null) {
            $this->error($device === null ? 'No such device.' : 'No such user.');

            return self::FAILURE;
        }

        try {
            $action->handle($device, $fingerprint, $owner, null, 'console');
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $this->info("Device {$device->id} approved for {$owner->username}.");

        return self::SUCCESS;
    }
}
