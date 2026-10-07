<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Currency\UpdateCompanyFxSettingRequest;
use App\Http\Resources\CompanyFxSettingResource;
use App\Services\Accounting\Currency\CompanyFxSettingService;
use App\Services\CompanyContext;

/**
 * Where the company's realised FX gains and losses post.
 *
 * The setting belongs to the active company and is addressed without an id: there is
 * exactly one per company, so a route binding would be ceremony over a singleton.
 * The service resolves (and creates on demand) the row for the context company,
 * which is also what makes this safe - nothing in the request can name another
 * company's setting.
 */
class CompanyFxSettingController extends Controller
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CompanyFxSettingService $settings,
    ) {}

    public function show(): CompanyFxSettingResource
    {
        $setting = $this->settings
            ->for($this->context->getOrFail())
            ->load(['realizedGainAccount', 'realizedLossAccount']);

        $this->authorize('view', $setting);

        return new CompanyFxSettingResource($setting);
    }

    public function update(UpdateCompanyFxSettingRequest $request): CompanyFxSettingResource
    {
        $setting = $this->settings->update(
            $this->context->getOrFail(),
            $request->validated(),
            $request->user(),
        );

        return new CompanyFxSettingResource(
            $setting->load(['realizedGainAccount', 'realizedLossAccount'])
        );
    }
}
