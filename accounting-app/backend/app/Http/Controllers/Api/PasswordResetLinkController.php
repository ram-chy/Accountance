<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * Send a password reset link.
     *
     * The same message is returned whether or not the account exists, so this
     * endpoint cannot be used to discover registered email addresses.
     */
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        /*
         | The broker's return value distinguishes an unknown address from a
         | delivered mail. It is deliberately discarded so the HTTP response is
         | byte-for-byte identical either way.
         */
        Password::sendResetLink($request->validated());

        return ApiResponse::success(
            message: 'If the account exists, password recovery instructions have been sent.',
        );
    }
}
