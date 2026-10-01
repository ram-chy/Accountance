<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanySettingsRequest;
use App\Http\Resources\CompanySettingResource;
use App\Services\CompanyContext;
use App\Services\CompanySettingsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings for the company in the current context.
 *
 * These routes always act on the resolved context company. They take no company
 * id from the request, so there is nothing to tamper with.
 */
class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CompanySettingsService $settings,
    ) {}

    public function show(): JsonResponse
    {
        $company = $this->context->getOrFail();

        $this->authorize('viewSettings', $company);

        return ApiResponse::success(
            message: 'Company settings retrieved successfully.',
            data: new CompanySettingResource($this->settings->for($company)),
        );
    }

    public function update(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $company = $this->context->getOrFail();

        $updated = $this->settings->update($company, $request->settingsPayload());

        return ApiResponse::success(
            message: 'Company settings updated successfully.',
            data: new CompanySettingResource($updated),
        );
    }
}
