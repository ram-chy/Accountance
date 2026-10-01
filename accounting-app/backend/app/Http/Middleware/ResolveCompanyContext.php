<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the active company for company-scoped routes.
 *
 * Runs after `auth:api`, so a user is always known. The header is a hint: the
 * company is resolved only if the user is a member and the company is active.
 *
 * A header that names a company the caller may not use is answered with 403
 * when the id exists but the caller is not a member, and 404 when the id does
 * not exist at all. Keeping those distinct is a deliberate trade-off: probing
 * "does company 999 exist" is a much smaller leak than the alternative of
 * letting a member silently fall back to their default and issue a write
 * against the wrong company.
 */
class ResolveCompanyContext
{
    public function __construct(private readonly CompanyContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error(message: 'Unauthenticated.', status: 401);
        }

        $requested = $this->context->requestedId();

        if ($requested !== null) {
            $company = $this->context->get();

            if (! $company instanceof Company) {
                $exists = Company::query()->whereKey($requested)->exists();

                return ApiResponse::error(
                    message: $exists
                        ? 'You do not have access to the selected company.'
                        : 'The requested company was not found.',
                    status: $exists ? 403 : 404,
                );
            }
        }

        if (! $this->context->has()) {
            return ApiResponse::error(
                message: 'No company is associated with your account, or the selected company is inactive.',
                status: 403,
            );
        }

        return $next($request);
    }
}
