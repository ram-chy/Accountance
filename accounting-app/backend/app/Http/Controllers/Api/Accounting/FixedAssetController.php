<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\FixedAssetAcquisitionMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FixedAssets\CapitalizeFixedAssetRequest;
use App\Http\Requests\Accounting\FixedAssets\DepreciateFixedAssetRequest;
use App\Http\Requests\Accounting\FixedAssets\DisposeFixedAssetRequest;
use App\Http\Requests\Accounting\FixedAssets\FixedAssetDepreciationReportRequest;
use App\Http\Requests\Accounting\FixedAssets\FixedAssetFilterRequest;
use App\Http\Requests\Accounting\FixedAssets\StoreFixedAssetRequest;
use App\Http\Requests\Accounting\FixedAssets\UpdateFixedAssetRequest;
use App\Http\Resources\FixedAssetDepreciationResource;
use App\Http\Resources\FixedAssetDisposalResource;
use App\Http\Resources\FixedAssetResource;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Services\Accounting\FixedAssets\DepreciationPeriod;
use App\Services\Accounting\FixedAssets\FixedAssetDepreciationService;
use App\Services\Accounting\FixedAssets\FixedAssetDisposalService;
use App\Services\Accounting\FixedAssets\FixedAssetService;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fixed asset endpoints.
 *
 * THE ACQUISITION METHOD IS THE ROUTE, NOT THE PAYLOAD
 *
 * There are two create endpoints - cash and supplier-credit - rather than one with a
 * method field, and that is the most consequential shape decision here. Which side of
 * the capitalisation entry is the money side depends entirely on the method, and the
 * account eligibility rule that follows from it ("a cash purchase must credit a
 * cash/bank account, a supplier-credit purchase a payable") has to be validated
 * against the method. If the method travelled in the body, that validation would be
 * performed against a value the client chose - which is exactly the situation
 * StoreCashBankTransactionRequest's docblock refuses to create for transaction types.
 * So the method is an argument the controller supplies from the route it was reached
 * through, and a client can no more declare a payment a cash purchase than it can
 * declare a withdrawal a deposit.
 *
 * Everything else follows the application's established split: the controller
 * resolves the active company from CompanyContext - never from the request - delegates
 * to the service of the moment, and shapes the response. The three irreversible
 * operations have their own endpoints and their own permissions, and the draft-only
 * edit and delete paths are guarded by the service, not by a field list.
 *
 * The route binding resolves a FixedAsset scoped to the active company, so an asset
 * reaching a method here is already known to belong to it and a foreign id has 404ed
 * before this class runs.
 */
class FixedAssetController extends Controller
{
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly FixedAssetDepreciationService $depreciations,
        private readonly FixedAssetDisposalService $disposals,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(FixedAssetFilterRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $assets = $this->filteredQuery($request, $company->getKey())
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Fixed assets retrieved successfully.',
            data: FixedAssetResource::collection($assets),
        );
    }

    /**
     * The asset register: assets still owned, with their written-down values.
     *
     * A separate endpoint from the listing rather than a filter on it, because it is a
     * different statement about the same table. The listing is "everything the company
     * has ever recorded", drafts and disposals included; the register is "what the
     * company owns", which is the ACTIVE and FULLY_DEPRECIATED rows and nothing else.
     * Expressing the second as an optional flag on the first would make the default
     * answer to "show me the register" depend on whether a client remembered the flag.
     */
    public function register(FixedAssetFilterRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $assets = $this->filteredQuery($request, $company->getKey())
            ->onRegister()
            ->orderBy('asset_number')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ApiResponse::success(
            message: 'Fixed asset register retrieved successfully.',
            data: FixedAssetResource::collection($assets),
        );
    }

    public function storeCash(StoreFixedAssetRequest $request): JsonResponse
    {
        return $this->store($request, FixedAssetAcquisitionMethod::Cash);
    }

    public function storeSupplierCredit(StoreFixedAssetRequest $request): JsonResponse
    {
        return $this->store($request, FixedAssetAcquisitionMethod::SupplierCredit);
    }

    public function show(FixedAsset $fixedAsset): JsonResponse
    {
        $this->authorize('view', $fixedAsset);

        return ApiResponse::success(
            message: 'Fixed asset retrieved successfully.',
            data: new FixedAssetResource($this->withRelations($fixedAsset, withDepreciations: true)),
        );
    }

    public function update(UpdateFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $updated = $this->assets->update($fixedAsset, $company, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Fixed asset updated successfully.',
            data: new FixedAssetResource($this->withRelations($updated)),
        );
    }

    public function destroy(Request $request, FixedAsset $fixedAsset): JsonResponse
    {
        $this->authorize('delete', $fixedAsset);

        $this->assets->delete($fixedAsset, $request->user());

        return ApiResponse::success(message: 'Fixed asset deleted successfully.');
    }

    public function capitalize(CapitalizeFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $capitalised = $this->assets->capitalise($fixedAsset, $request->user());

        return ApiResponse::success(
            message: 'Fixed asset capitalised successfully.',
            data: new FixedAssetResource($this->withRelations($capitalised)),
        );
    }

    /**
     * The asset's posted periods and its remaining schedule.
     *
     * Both, in one response, because they are two halves of one answer: the charges
     * already in the ledger and the ones still to come. The posted rows are loaded and
     * handed to each row's `fixedAsset` relation in memory, so the per-row
     * `is_final_period` flag costs no query. The upcoming periods are the calculator's
     * projection, taken from the same posted rows the posting path will read, so what
     * is shown and what will be charged cannot disagree.
     */
    public function depreciationSchedule(FixedAsset $fixedAsset): JsonResponse
    {
        $this->authorize('view', $fixedAsset);

        $asset = $this->withRelations($fixedAsset, withDepreciations: true);

        return ApiResponse::success(
            message: 'Fixed asset depreciation schedule retrieved successfully.',
            data: [
                'fixed_asset' => new FixedAssetResource($asset),
                'upcoming_periods' => array_map(
                    fn (DepreciationPeriod $period): array => [
                        'period_number' => $period->number,
                        'period_start_date' => $period->startDate->toDateString(),
                        'period_end_date' => $period->endDate->toDateString(),
                        'posting_date' => $period->postingDate()->toDateString(),
                        'amount' => $period->amount->toDatabase(),
                        'is_final_period' => $period->isFinal,
                    ],
                    $this->depreciations->schedule($asset)
                ),
            ],
        );
    }

    /**
     * Post the next depreciation charge.
     *
     * `as_of` only moves the date the "has this period ended" question is asked
     * against; the period, the amount and the posting date all come from the stored
     * schedule, which FixedAssetDepreciationService re-derives under the asset lock.
     * The asset is returned alongside the charge so a caller can see the status it
     * moved to without a second request.
     */
    public function depreciate(DepreciateFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $asOf = $request->filled('as_of')
            ? Carbon::parse($request->date('as_of'))
            : null;

        $depreciation = $this->depreciations->depreciate($fixedAsset, $request->user(), $asOf);

        return ApiResponse::success(
            message: 'Fixed asset depreciation posted successfully.',
            data: [
                'depreciation' => new FixedAssetDepreciationResource(
                    $depreciation->load('journal')
                ),
                'fixed_asset' => new FixedAssetResource($this->withRelations($fixedAsset->refresh())),
            ],
        );
    }

    public function dispose(DisposeFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $disposal = $this->disposals->dispose($fixedAsset, $request->user(), $request->validated());

        return ApiResponse::success(
            message: 'Fixed asset disposed of successfully.',
            data: [
                'disposal' => new FixedAssetDisposalResource($disposal->load('journal')),
                'fixed_asset' => new FixedAssetResource($this->withRelations($fixedAsset->refresh())),
            ],
        );
    }

    /**
     * Depreciation posted within a date range, grouped by asset.
     *
     * The range is matched against each charge's own period dates, which is what makes
     * a run performed in arrears report correctly: charging six months in September
     * still files each charge under the month it covers. The grouping is done in PHP
     * rather than SQL because each group carries its own rows and a sum, and one query
     * returns both without a second pass.
     */
    public function depreciationReport(FixedAssetDepreciationReportRequest $request): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $rows = FixedAssetDepreciation::query()
            ->where('company_id', $company->getKey())
            ->inPeriod($request->date('from'), $request->date('to'))
            ->when(
                $request->filled('fixed_asset_category_id'),
                fn ($query) => $query->whereHas(
                    'fixedAsset',
                    fn ($asset) => $asset->where('fixed_asset_category_id', $request->integer('fixed_asset_category_id'))
                ),
            )
            ->with(['fixedAsset.category', 'journal'])
            ->get();

        $byAsset = $rows
            ->groupBy('fixed_asset_id')
            ->map(function ($group): array {
                /** @var FixedAssetDepreciation $first */
                $first = $group->first();
                $asset = $first->fixedAsset;

                return [
                    'fixed_asset_id' => $asset->getKey(),
                    'asset_number' => $asset->asset_number,
                    'name' => $asset->name,
                    'category' => $asset->category?->name,
                    'period_count' => $group->count(),
                    'total_depreciation' => $this->totalOf($group)->toDatabase(),
                    'entries' => FixedAssetDepreciationResource::collection($group->values()),
                ];
            })
            ->values();

        return ApiResponse::success(
            message: 'Fixed asset depreciation report retrieved successfully.',
            data: [
                'from' => $request->date('from')->toDateString(),
                'to' => $request->date('to')->toDateString(),
                'total_depreciation' => $this->totalOf($rows)->toDatabase(),
                'assets' => $byAsset,
            ],
        );
    }

    private function store(StoreFixedAssetRequest $request, FixedAssetAcquisitionMethod $method): JsonResponse
    {
        $company = $this->companyContext->getOrFail();

        $asset = $this->assets->create($company, $request->user(), $method, $request->validated());

        return ApiResponse::success(
            message: 'Fixed asset created successfully.',
            data: new FixedAssetResource($this->withRelations($asset)),
            status: 201,
        );
    }

    /**
     * The filters shared by the listing and the register.
     *
     * `withSum` and `withMax` are what keep a page of assets from running two
     * aggregate queries per row for the derived figures; the resource's accessors
     * prefer the aggregated attributes when they are present. Only the category and
     * the asset account are eager-loaded, because a list does not need all five
     * account objects - the resource emits whichever of them are loaded.
     */
    private function filteredQuery(FixedAssetFilterRequest $request, int $companyId)
    {
        return FixedAsset::query()
            ->where('company_id', $companyId)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('acquisition_method'),
                fn ($query) => $query->where('acquisition_method', $request->string('acquisition_method'))
            )
            ->when(
                $request->filled('fixed_asset_category_id'),
                fn ($query) => $query->where('fixed_asset_category_id', $request->integer('fixed_asset_category_id'))
            )
            ->when(
                $request->filled('from'),
                fn ($query) => $query->whereDate('acquisition_date', '>=', $request->date('from')->toDateString())
            )
            ->when(
                $request->filled('to'),
                fn ($query) => $query->whereDate('acquisition_date', '<=', $request->date('to')->toDateString())
            )
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('asset_number', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)
                    ->orWhere('supplier_reference', 'like', $term));
            })
            ->with(['category', 'assetAccount'])
            ->withSum('depreciations', 'amount')
            ->withMax('depreciations', 'period_number')
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id');
    }

    private function withRelations(FixedAsset $asset, bool $withDepreciations = false): FixedAsset
    {
        $asset->load([
            'category',
            'assetAccount',
            'accumulatedDepreciationAccount',
            'depreciationExpenseAccount',
            'gainOnDisposalAccount',
            'lossOnDisposalAccount',
            'acquisitionAccount',
            'journal',
        ]);

        if ($withDepreciations) {
            $asset->load(['depreciations.journal', 'disposal.proceedsAccount', 'disposal.journal']);

            /*
             * Point every posted row back at the asset already in hand, so the
             * resource's is_final_period flag reads the period count without a query
             * per row. The relation is the same object, not a copy.
             */
            $asset->depreciations->each(
                fn (FixedAssetDepreciation $row) => $row->setRelation('fixedAsset', $asset)
            );
        }

        return $asset;
    }

    /**
     * Sum a set of depreciation rows exactly.
     *
     * A Money reduction rather than Collection::sum(), which would add the DECIMAL
     * strings as floats and drift across a long report.
     *
     * @param  Collection<int, FixedAssetDepreciation>  $rows
     */
    private function totalOf(Collection $rows): Money
    {
        return $rows->reduce(
            fn (Money $carry, FixedAssetDepreciation $row): Money => $carry->plus(Money::of($row->amount)),
            Money::zero()
        );
    }
}
