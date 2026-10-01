<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Log a user in and issue a JWT.
     *
     * Failures are deliberately indistinguishable: an unknown email, a wrong
     * password and a deactivated account all return the same message with the
     * same status, so the endpoint cannot be used to enumerate accounts.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $this->failedLogin();
        }

        if (! $user->is_active) {
            return $this->failedLogin();
        }

        $token = JWTAuth::fromUser($user);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return ApiResponse::success(
            message: 'Login successful.',
            data: [
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user' => new UserResource($user->load('roles')),
            ],
        );
    }

    /**
     * Revoke the token used for the current request only.
     */
    public function destroy(Request $request): JsonResponse
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return ApiResponse::success(message: 'Logged out successfully.');
    }

    private function failedLogin(): JsonResponse
    {
        return ApiResponse::error(
            message: 'These credentials do not match our records.',
            status: 401,
        );
    }
}
