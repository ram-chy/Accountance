<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureTokenIsFresh;
use App\Http\Middleware\ResolveCompanyContext;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         | On the global stack, not the api group. The id is useful on every
         | response - authenticated or not, API or not - and the api group is
         | not pre-populated in this application, so appending here is the one
         | registration that cannot be forgotten by a route added later.
         */
        $middleware->append(AssignRequestId::class);

        $middleware->alias([
            'auth.fresh' => EnsureTokenIsFresh::class,
            'company.context' => ResolveCompanyContext::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage(),
                errors: $e->errors(),
                status: $e->status,
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(message: 'Unauthenticated.', status: 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage() ?: 'This action is unauthorized.',
                status: 403,
            );
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(message: 'Resource not found.', status: 404);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The requested method is not supported for this route.',
                status: 405,
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'The requested endpoint was not found.',
                status: 404,
            );
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'Too many requests. Please try again later.',
                status: 429,
                headers: $e->getHeaders(),
            );
        });

        /*
         | The JWT package raises its own exception hierarchy rather than
         | Laravel's AuthenticationException, so each variant is mapped to a
         | deliberate status. Without this an expired token would surface as a
         | generic 500, which is both wrong and a needless information leak.
         */
        $exceptions->render(function (TokenExpiredException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'Your token has expired. Please sign in again.',
                status: 401,
            );
        });

        $exceptions->render(function (TokenInvalidException|TokenBlacklistedException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                message: 'Your token is no longer valid. Please sign in again.',
                status: 401,
            );
        });

        $exceptions->render(function (JWTException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // JWTException is the parent of TokenExpired/TokenInvalid, so this
            // catches the remaining cases (malformed, missing, unsigned).
            return ApiResponse::error(
                message: 'Your token could not be verified. Please sign in again.',
                status: 401,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*') || $e->getStatusCode() >= 500) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage() ?: 'The request could not be completed.',
                status: $e->getStatusCode(),
                headers: $e->getHeaders(),
            );
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || app()->hasDebugModeEnabled()) {
                return null;
            }

            return ApiResponse::error(
                message: 'An unexpected error occurred.',
                status: 500,
            );
        });
    })->create();
