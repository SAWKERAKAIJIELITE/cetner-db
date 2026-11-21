<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Support\LoginResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The bearer token of a login and when it ends (the next 00:00 in Damascus, D-009).
 *
 * @mixin LoginResult
 */
class LoginResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'token_type' => 'Bearer',
            'expires_at' => $this->expiresAt->toIso8601String(),
            'user' => (new StaffUserResource($this->user->load('role')))->withPermissions(),
        ];
    }
}
