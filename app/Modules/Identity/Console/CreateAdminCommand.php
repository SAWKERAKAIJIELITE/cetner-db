<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\RegisterStaffUser;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Http\Requests\RegisterUserRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The first system administrator, from the shell (D-018): a new installation has nobody who could add
 * a user from the application. The password is asked twice and never passed as an argument.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'staff:create-admin {username}
        {--first-name= : First name}
        {--last-name= : Last name}
        {--personal-number= : Digits only, 4 to 50}';

    protected $description = 'Create a system administrator account';

    public function handle(RegisterStaffUser $action): int
    {
        $role = Role::query()->where('slug', StaffRole::SystemAdmin->value)->first();

        if ($role === null) {
            $this->error('The roles are not seeded: run php artisan db:seed --force first.');

            return self::FAILURE;
        }

        $activeAdmins = StaffUser::query()->where('is_active', true)->where('role_id', $role->getKey())->count();

        if ($activeAdmins > 0 && ! $this->confirm("{$activeAdmins} active administrator(s) exist already. Create another?", false)) {
            return self::FAILURE;
        }

        $data = [
            'username' => (string) $this->argument('username'),
            'first_name' => (string) ($this->option('first-name') ?? $this->ask('First name')),
            'last_name' => (string) ($this->option('last-name') ?? $this->ask('Last name')),
            'personal_number' => (string) ($this->option('personal-number') ?? $this->ask('Personal number')),
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Password again'),
            'role' => $role->slug,
        ];

        // The API's own rules (normalisation included).
        $request = RegisterUserRequest::create('/', 'POST', $data);
        $request->setContainer(app());
        (fn () => $this->prepareForValidation())->call($request);
        $validator = Validator::make($request->all(), $request->rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = $action->handle($validator->validated(), $role, null, 'console');

        $this->info("Administrator {$user->username} created ({$user->id}).");
        $this->line('Next: register the administrator\'s computer, then php artisan staff:approve-device <device-id> '.$user->username.' --fingerprint="…"');

        return self::SUCCESS;
    }
}
