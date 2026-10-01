<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class PasswordController extends Controller
{
    /**
     * Change the authenticated user's password.
     *
     * Saving the new password changes the user's password-version fingerprint,
     * so the `auth.fresh` middleware rejects every token minted before this
     * change. The token used for this request is explicitly blacklisted and a
     * replacement is returned, so the caller stays signed in and the old token
     * cannot be replayed.
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->validated('new_password')])->save();

        // The stored hash changes, so this token's `pv` claim is now stale.
        // Blacklist it explicitly so a replay of the old token is refused even
        // if the grace period would otherwise still admit it.
        JWTAuth::invalidate(JWTAuth::getToken());

        $token = JWTAuth::fromUser($user->fresh());

        return ApiResponse::success(
            message: 'Password changed successfully.',
            data: [
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
            ],
        );
    }
}
