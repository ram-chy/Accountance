<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Currency\StoreExchangeRateRequest;
use App\Http\Requests\Accounting\Currency\UpdateExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Services\Accounting\Currency\ExchangeRateService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Exchange rates for the active company.
 *
 * The company is resolved from CompanyContext and every lookup is scoped to it, so a
 * rate id from another company 404s at the route binding before a method runs. The
 * controller adds no company filter of its own beyond that.
 *
 * A rate is dated history, never a mutable current value: it may be corrected only
 * while it has not priced a document, and the correction path is ExchangeRateService,
 * which owns that rule.
 */
class ExchangeRateController extends Controller
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly ExchangeRateService $rates,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ExchangeRate::class);

        $query = ExchangeRate::query()
            ->where('company_id', $this->company()->getKey())
            ->with(['fromCurrency', 'toCurrency']);

        if ($request->filled('from_currency_id')) {
            $query->where('from_currency_id', (int) $request->input('from_currency_id'));
        }

        if ($request->filled('to_currency_id')) {
            $query->where('to_currency_id', (int) $request->input('to_currency_id'));
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        return ExchangeRateResource::collection(
            $query->orderByDesc('effective_date')->orderByDesc('id')->get()
        );
    }

    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        $rate = $this->rates->create($this->company(), $request->validated());

        return (new ExchangeRateResource($rate->load(['fromCurrency', 'toCurrency'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ExchangeRate $exchangeRate): ExchangeRateResource
    {
        $this->authorize('view', $exchangeRate);

        return new ExchangeRateResource($exchangeRate->load(['fromCurrency', 'toCurrency']));
    }

    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchangeRate): ExchangeRateResource
    {
        return new ExchangeRateResource(
            $this->rates->update($exchangeRate, $request->validated())
                ->load(['fromCurrency', 'toCurrency'])
        );
    }

    public function activate(ExchangeRate $exchangeRate): JsonResponse
    {
        $this->authorize('activate', $exchangeRate);

        return ApiResponse::success(
            message: 'Exchange rate activated successfully.',
            data: new ExchangeRateResource(
                $this->rates->activate($exchangeRate)->load(['fromCurrency', 'toCurrency'])
            ),
        );
    }

    public function deactivate(ExchangeRate $exchangeRate): JsonResponse
    {
        $this->authorize('deactivate', $exchangeRate);

        return ApiResponse::success(
            message: 'Exchange rate deactivated successfully.',
            data: new ExchangeRateResource(
                $this->rates->deactivate($exchangeRate)->load(['fromCurrency', 'toCurrency'])
            ),
        );
    }

    private function company(): Company
    {
        return $this->context->getOrFail();
    }
}
