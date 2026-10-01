<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-authentication token freshness checks.
 *
 * The JWT itself is stateless, so signing a token does not make it revocable by
 * the database. Two conditions are therefore re-checked on every authenticated
 * request:
 *
 *  1. The user still exists and is active. A deactivated account must not keep
 *     working until its token expires.
 *  2. The token's `pv` (password version) claim still matches the user's
 *     current password fingerprint. Changing or resetting a password therefore
 *     invalidates every token issued beforehand, including tokens an attacker
 *     holds and that were never blacklisted.
 */
class EnsureTokenIsFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        // The payload is read from the resolved API guard rather than from the
        // JWTAuth facade: the facade has no token set during a normal request,
        // whereas the guard does, because auth:api already parsed it.
        $guard = Auth::guard('api');

        if (! $guard instanceof JWTGuard) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (! $user->is_active) {
            $guard->invalidate();

            return ApiResponse::error(
                message: 'This account has been deactivated.',
                status: 403,
            );
        }

        $tokenVersion = $guard->getPayload()->get('pv');

        if (! is_string($tokenVersion) || ! hash_equals($user->passwordVersion(), $tokenVersion)) {
            // The stored hash changed after this token was minted, so it is
            // both stale and blacklisted.
            $guard->invalidate();

            return ApiResponse::error(
                message: 'This token is no longer valid. Please sign in again.',
                status: 401,
            );
        }

        return $next($request);
    }
}
