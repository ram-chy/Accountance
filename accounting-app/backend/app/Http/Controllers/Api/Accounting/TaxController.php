<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\TaxCalculationBasis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreTaxRequest;
use App\Http\Requests\Accounting\Tax\CalculateTaxRequest;
use App\Http\Requests\Accounting\Tax\StoreTaxRateRequest;
use App\Http\Requests\Accounting\Tax\UpdateTaxAccountMappingRequest;
use App\Http\Requests\Accounting\Tax\UpdateTaxRateRequest;
use App\Http\Requests\Accounting\UpdateTaxRequest;
use App\Http\Resources\TaxAccountMappingResource;
use App\Http\Resources\TaxCalculationResource;
use App\Http\Resources\TaxRateResource;
use App\Http\Resources\TaxResource;
use App\Models\Company;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Services\Accounting\Tax\TaxAccountMappingService;
use App\Services\Accounting\Tax\TaxCalculationService;
use App\Services\Accounting\Tax\TaxRateService;
use App\Services\Accounting\Tax\TaxRuleResolver;
use App\Services\Accounting\Tax\TaxService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tax configuration and calculation.
 *
 * Configuration is CRUD over three resources - taxes, their rates, and their
 * account mapping - plus one non-writing calculation endpoint.
 *
 * Two things are true of every method here:
 *
 *  1. The company comes from CompanyContext, never from the request. `tax` and
 *     `rate` are company-scoped in AppServiceProvider's route binding, so an id
 *     from another tenant 404s before a method body runs, and TaxRuleResolver
 *     applies the same scope to the tax ids a request names in its body - a path
 *     route binding cannot cover.
 *
 *  2. calculate() persists nothing. It reads configuration, computes, and
 *     returns. There is no create, update or delete on that path, and the
 *     request it takes carries no document id - so calling it cannot move money
 *     or write a rate.
 */
class TaxController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly TaxService $taxes,
        private readonly TaxRateService $rates,
        private readonly TaxAccountMappingService $mappings,
        private readonly TaxCalculationService $calculator,
        private readonly TaxRuleResolver $rules,
    ) {}

    /**
     * List the active company's taxes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tax::class);

        /*
         * `active_only` exists because a configuration screen and a document form
         * want opposite things: the first must show retired taxes so they are not
         * simply missing, the second must offer only what may be applied. Both are
         * reads of the same list.
         */
        $activeOnly = $request->boolean('active_only');

        $taxes = $this->taxes->listFor($this->company(), $activeOnly)
            ->load(['rates', 'accountMapping.outputAccount', 'accountMapping.inputAccount']);

        return TaxResource::collection($taxes);
    }

    public function store(StoreTaxRequest $request): JsonResponse
    {
        $tax = $this->taxes->create($this->company(), $request->validated());

        return (new TaxResource($tax->load(['rates', 'accountMapping'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tax $tax): TaxResource
    {
        $this->authorize('view', $tax);

        return new TaxResource(
            $tax->load(['rates', 'accountMapping.outputAccount', 'accountMapping.inputAccount'])
        );
    }

    public function update(UpdateTaxRequest $request, Tax $tax): TaxResource
    {
        return new TaxResource(
            $this->taxes->update($tax, $request->validated())->load(['rates', 'accountMapping'])
        );
    }

    public function destroy(Tax $tax): JsonResponse
    {
        $this->authorize('delete', $tax);

        $this->taxes->delete($tax);

        return response()->json(['message' => 'Tax deleted.']);
    }

    /**
     * Make a tax available for new documents again.
     *
     * A separate route, not a `PUT {"is_active": true}`, for the same reason
     * accounts and companies have one: deactivation and reactivation are audited
     * lifecycle acts with their own authorization, and burying them in a general
     * update would let them ride in on any field-level change.
     *
     * Reactivating never rewrites history. Documents charged while the tax was
     * inactive keep the snapshot they were given, and the tax's rate history is
     * untouched - so reactivating resumes charging the rate that was already in
     * force rather than inventing one.
     */
    public function activate(Tax $tax): JsonResponse
    {
        $this->authorize('activate', $tax);

        return ApiResponse::success(
            message: 'Tax activated successfully.',
            data: new TaxResource($this->taxes->activate($tax)),
        );
    }

    /**
     * Stop applying a tax to new documents, without destroying it.
     *
     * The safe alternative to deletion when documents already reference the tax,
     * and the only way to stop being charged it without rewriting what was
     * already charged.
     */
    public function deactivate(Tax $tax): JsonResponse
    {
        $this->authorize('deactivate', $tax);

        return ApiResponse::success(
            message: 'Tax deactivated successfully.',
            data: new TaxResource($this->taxes->deactivate($tax)),
        );
    }

    /**
     * A tax's rate history, newest period last.
     */
    public function rates(Tax $tax): AnonymousResourceCollection
    {
        $this->authorize('viewAnyRate', $tax);

        return TaxRateResource::collection($tax->rates()->get());
    }

    public function storeRate(StoreTaxRateRequest $request, Tax $tax): JsonResponse
    {
        $rate = $this->rates->create($tax, $request->validated());

        return (new TaxRateResource($rate))->response()->setStatusCode(201);
    }

    public function updateRate(UpdateTaxRateRequest $request, Tax $tax, TaxRate $rate): TaxRateResource
    {
        /*
         * The rate is company-scoped by route binding, but it is not yet known to
         * belong to *this* tax - a rate id from a different tax in the same company
         * would otherwise be silently re-parented by the service.
         */
        $rateModel = $this->rates->findForTax($tax, $rate->getKey());

        return new TaxRateResource($this->rates->update($rateModel, $request->validated()));
    }

    public function destroyRate(Tax $tax, TaxRate $rate): JsonResponse
    {
        $rateModel = $this->rates->findForTax($tax, $rate->getKey());

        $this->authorize('deleteRate', $rateModel);

        $this->rates->delete($rateModel);

        return response()->json(['message' => 'Tax rate deleted.']);
    }

    /**
     * Make a single rate period usable again.
     *
     * Separate from reactivating the tax, because they are different acts with
     * different causes: a tax may be deactivated wholesale for a quarter and
     * restored, or one rate period may be retired - a rate that was entered in
     * error - and put back.
     */
    public function activateRate(Tax $tax, TaxRate $rate): JsonResponse
    {
        $rateModel = $this->rates->findForTax($tax, $rate->getKey());

        $this->authorize('activateRate', $rateModel);

        return ApiResponse::success(
            message: 'Tax rate activated successfully.',
            data: new TaxRateResource($this->rates->activate($rateModel)),
        );
    }

    /**
     * Retire a rate period so it is skipped by resolution.
     *
     * Distinct from deleting it. The row stays, so the effective-dated history
     * still explains which rate was in force on a given day, and a document that
     * was calculated with it keeps its explanation.
     */
    public function deactivateRate(Tax $tax, TaxRate $rate): JsonResponse
    {
        $rateModel = $this->rates->findForTax($tax, $rate->getKey());

        $this->authorize('deactivateRate', $rateModel);

        return ApiResponse::success(
            message: 'Tax rate deactivated successfully.',
            data: new TaxRateResource($this->rates->deactivate($rateModel)),
        );
    }

    /**
     * Where this tax's money posts.
     *
     * Always returns a resource, even when no mapping exists - a client rendering
     * a mapping form needs to distinguish "not configured" from "no such tax", and
     * a 404 would collapse the two.
     */
    public function accountMapping(Tax $tax): TaxAccountMappingResource
    {
        $this->authorize('viewMapping', $tax);

        return new TaxAccountMappingResource(
            $tax->accountMapping()->with(['outputAccount', 'inputAccount'])->first()
        );
    }

    public function updateAccountMapping(UpdateTaxAccountMappingRequest $request, Tax $tax): JsonResponse
    {
        $mapping = $this->mappings->save($tax, $request->validated());

        /*
         * The status code is set explicitly rather than left to the resource.
         *
         * A resource answers 201 when the model behind it was just created, which
         * here is always: the service replaces the mapping by deleting and
         * inserting a row. Left to itself, every call to this endpoint would answer
         * 201 - including calls that replaced an existing mapping - so the status
         * would describe the service's upsert strategy rather than what the client
         * asked for. This is a PUT with full-replacement semantics, so it is a 200
         * whether or not a mapping happened to exist before.
         */
        return (new TaxAccountMappingResource($mapping->load(['outputAccount', 'inputAccount'])))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Calculate tax on an amount.
     *
     * Writes nothing. No model is created or updated on this path, and there is no
     * company id in the request: the taxes are resolved from the active company and
     * a named id from another tenant comes back as a validation error naming it,
     * which is the same answer a client gets for an id that does not exist.
     */
    public function calculate(CalculateTaxRequest $request): TaxCalculationResource
    {
        $company = $this->company();
        $data = $request->validated();

        /*
         * tax_ids absent and tax_ids [] mean the same thing - no taxes - and both
         * must produce an untaxed result rather than a 422. An empty list is a
         * legitimate question ("is this figure already inclusive?"), not a mistake.
         */
        $taxIds = collect($data['tax_ids'] ?? [])->values();

        $taxes = $this->rules->resolve($company, true, $data['date'], $taxIds);

        $result = $this->calculator->calculate(
            amount: Money::ofTolerant($data['amount']),
            taxes: $taxes,
            date: $data['date'],
            basis: isset($data['basis']) && $data['basis'] !== null
                ? TaxCalculationBasis::from($data['basis'])
                : null,
            field: 'tax_ids',
        );

        return new TaxCalculationResource($result);
    }

    private function company(): Company
    {
        return $this->companyContext->getOrFail();
    }
}
