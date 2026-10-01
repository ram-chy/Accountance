<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class NewPasswordController extends Controller
{
    /**
     * Reset a password using a valid reset token.
     *
     * The token is single use: the broker deletes it once consumed. Rewriting
     * the password also changes the user's password-version fingerprint, so the
     * `auth.fresh` middleware rejects every JWT issued before the reset,
     * including tokens an attacker holds that were never blacklisted.
     */
    public function store(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return ApiResponse::error(
                message: __($status),
                status: 400,
            );
        }

        return ApiResponse::success(message: __($status));
    }

    /**
     * Report whether a reset token is still usable.
     *
     * The emailed link targets this GET route because the backend renders no
     * form. It tells a client whether to show a "choose a new password" screen;
     * it never accepts the new password itself, which stays a POST.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $user = $this->findUserByEmail((string) $request->query('email', ''));

        $isValid = $user !== null
            && Password::broker()->getRepository()->exists($user, $token);

        if (! $isValid) {
            /*
             * Covers both an unknown address and a stale token with one
             * response, so the endpoint cannot be used to test whether an email
             * address is registered.
             */
            return ApiResponse::error(
                message: 'This password reset link is invalid or has expired.',
                status: 400,
            );
        }

        return ApiResponse::success(
            message: 'This reset link is valid. Send the new password to POST /api/auth/reset-password.',
            data: ['email' => $user->getEmailForPasswordReset()],
        );
    }

    private function findUserByEmail(string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        return User::query()->where('email', mb_strtolower(trim($email)))->first();
    }
}
