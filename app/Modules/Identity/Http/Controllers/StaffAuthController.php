<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Actions\IssueLoginChallenge;
use App\Modules\Identity\Actions\LoginStaff;
use App\Modules\Identity\Http\Requests\ChallengeRequest;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Resources\LoginResource;
use App\Modules\Identity\Http\Resources\StaffUserResource;
use App\Modules\Identity\Models\AccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffAuthController extends Controller
{
    /**
     * Get a login challenge for an approved device.
     *
     * @unauthenticated
     */
    public function challenge(ChallengeRequest $request, IssueLoginChallenge $action): JsonResponse
    {
        $challenge = $action->handle($request->validated('device_id'));

        return response()->json([
            'challenge_id' => $challenge->id,
            'challenge' => $challenge->challenge,
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ], 201);
    }

    /**
     * Log in with the device's signature, the username and the password.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request, LoginStaff $action): LoginResource
    {
        $data = $request->validated();

        return new LoginResource($action->handle(
            $data['device_id'], $data['challenge_id'], $data['signature'], $data['username'], $data['password'],
        ));
    }

    /** End this session (the token used). */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        DB::transaction(function () use ($user, $token) {
            AuditTrail::record('auth.logout', $user, ['device_id' => $token instanceof AccessToken ? $token->staff_device_id : null]);

            if ($token instanceof AccessToken) {
                $token->delete();
            }
        });

        return response()->json(['message' => __('identity.logged_out')]);
    }

    /** The signed-in user with their role and permissions. */
    public function me(Request $request): StaffUserResource
    {
        return (new StaffUserResource($request->user()->load('role')))->withPermissions();
    }
}
