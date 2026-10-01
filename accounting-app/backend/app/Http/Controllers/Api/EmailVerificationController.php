<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Consume a signed verification link and mark the email as verified.
     *
     * This endpoint is public because the link is opened from a mail client,
     * where no bearer token is available. Authorisation comes from the URL
     * signature, which Laravel generates with a short expiry, plus the `id` and
     * `hash` parameters. A valid signature proves the link came from this
     * server; the hash proves the address has not changed since it was sent.
     */
    public function update(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            // Do not disclose whether the account exists.
            return $this->invalidLink();
        }

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return $this->invalidLink();
        }

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(message: 'Email address is already verified.');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return ApiResponse::success(message: 'Email address verified successfully.');
    }

    /**
     * Resend the verification notification to the signed-in user.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(message: 'Email address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return ApiResponse::success(
            message: 'A fresh verification link has been sent to your email address.',
        );
    }

    private function invalidLink(): JsonResponse
    {
        return ApiResponse::error(
            message: 'This verification link is invalid or has expired. Request a new one.',
            status: 403,
        );
    }
}
