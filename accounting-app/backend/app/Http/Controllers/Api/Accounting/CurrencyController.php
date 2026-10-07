<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\Currency\StoreCurrencyRequest;
use App\Http\Requests\Accounting\Currency\UpdateCurrencyRequest;
use App\Http\Resources\CurrencyResource;
use App\Models\Currency;
use App\Services\Accounting\Currency\CurrencyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The currency master.
 *
 * Currencies are GLOBAL: the list is readable by any authenticated user who is
 * working inside a company (a picker has to show what the company can invoice in),
 * and writable only under the explicit `accounting.currency.*` capabilities, which
 * are held by Admin alone. There is no company id in any request, because there is
 * no company to scope a currency to.
 *
 * There is no destroy. A currency a posted document or a rate referenced must stay
 * readable forever, so the lifecycle ends at deactivation, which is a separate route
 * with its own grant and its own audit row.
 */
class CurrencyController extends Controller
{
    public function __construct(
        private readonly CurrencyService $currencies,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Currency::class);

        $query = Currency::query();

        if ($request->boolean('active_only')) {
            $query->active();
        }

        return CurrencyResource::collection($query->orderBy('code')->get());
    }

    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        $currency = $this->currencies->create($request->validated());

        return (new CurrencyResource($currency))->response()->setStatusCode(201);
    }

    public function show(Currency $currency): CurrencyResource
    {
        $this->authorize('view', $currency);

        return new CurrencyResource($currency);
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): CurrencyResource
    {
        return new CurrencyResource($this->currencies->update($currency, $request->validated()));
    }

    public function activate(Currency $currency): JsonResponse
    {
        $this->authorize('activate', $currency);

        return ApiResponse::success(
            message: 'Currency activated successfully.',
            data: new CurrencyResource($this->currencies->activate($currency)),
        );
    }

    public function deactivate(Currency $currency): JsonResponse
    {
        $this->authorize('deactivate', $currency);

        return ApiResponse::success(
            message: 'Currency deactivated successfully.',
            data: new CurrencyResource($this->currencies->deactivate($currency)),
        );
    }
}
