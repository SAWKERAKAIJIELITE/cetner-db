<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\StaffUser;
use Carbon\CarbonImmutable;

/** What a successful login gives back: the bearer token, when it ends, and who it is for. */
final readonly class LoginResult
{
    public function __construct(
        public string $token,
        public CarbonImmutable $expiresAt,
        public StaffUser $user,
    ) {}
}
