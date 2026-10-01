<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CompanyContext;
use App\Services\CompanyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Company context endpoints.
 *
 * The active company is decided by the X-Company-Id header, validated against
 * membership on every request. "Switching" therefore records the user's
 * persistent default and returns that company; it does not mint a new token
 * and does not rely on any client-held state. A header that the caller is not
 * entitled to is rejected rather than silently ignored, so a client can never
 * believe it is operating inside a company it cannot reach.
 */
class CompanyContextController extends Controller
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CompanyService $companies,
    ) {}

    /**
     * The company this request is operating in.
     */
    public function show(): JsonResponse
    {
        return ApiResponse::success(
            message: 'Active company retrieved successfully.',
            data: new CompanyResource($this->context->getOrFail()->load('settings')),
        );
    }

    /**
     * Make a company the caller's default.
     */
    public function switch(Request $request, Company $company): JsonResponse
    {
        $this->authorize('switch', $company);

        $this->companies->makeDefault($request->user(), $company);

        return ApiResponse::success(
            message: 'Active company switched successfully.',
            data: new CompanyResource($company->load('settings')),
            // Echo the header the client should send on subsequent requests.
            headers: [CompanyContext::HEADER => (string) $company->getKey()],
        );
    }
}
