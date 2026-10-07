<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Currency\ChangeBaseCurrencyRequest;
use App\Http\Requests\UpdateCompanySettingsRequest;
use App\Http\Resources\CompanySettingResource;
use App\Models\Currency;
use App\Services\Accounting\Currency\CompanyCurrencyService;
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
        private readonly CompanyCurrencyService $companyCurrency,
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

    /**
     * Change the company's functional (base) currency.
     *
     * A dedicated route rather than a field on the settings update, because the
     * change is a different act with a different consequence: it reinterprets every
     * stored base amount, so it carries a safety refusal and its own audit entry,
     * neither of which belongs in a settings form submit. The service owns that
     * safety rule and the audit; this method only resolves the target and reports
     * the result.
     */
    public function changeBaseCurrency(ChangeBaseCurrencyRequest $request): JsonResponse
    {
        $company = $this->context->getOrFail();

        $currency = Currency::query()->findOrFail($request->integer('currency_id'));

        $company = $this->companyCurrency->changeBaseCurrency($company, $currency, $request->user());

        return ApiResponse::success(
            message: 'Company base currency updated successfully.',
            data: [
                'company_id' => $company->getKey(),
                'base_currency' => $company->currency?->code,
            ],
        );
    }
}
