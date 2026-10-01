<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManageCompanyMemberRequest;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companies) {}

    /**
     * List the companies the caller belongs to.
     *
     * The query is scoped through the membership relation rather than filtered
     * afterwards, so a company the caller is not a member of cannot appear even
     * if the permission check is later relaxed.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        $user = $request->user();

        $companies = $user->companies()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($q) => $q
                    ->where('companies.name', 'like', $term)
                    ->orWhere('companies.legal_name', 'like', $term));
            })
            ->when($request->has('is_active'), fn ($query) => $query->where(
                'companies.is_active',
                $request->boolean('is_active'),
            ))
            ->orderBy('companies.name')
            // Eager loaded so every endpoint returns the same shape. Without
            // this the resource falls back to null for each row, and adding the
            // load per company would mean one query per row.
            ->with('settings')
            ->get();

        return ApiResponse::success(
            message: 'Companies retrieved successfully.',
            data: CompanyResource::collection($companies),
        );
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = $this->companies->create(
            $request->user(),
            $request->validated(),
            isDefault: (bool) $request->boolean('is_default', true),
        );

        return ApiResponse::success(
            message: 'Company created successfully.',
            data: new CompanyResource($company->load('settings')),
            status: 201,
        );
    }

    /**
     * Show a company. Membership is enforced by CompanyPolicy::view.
     */
    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return ApiResponse::success(
            message: 'Company retrieved successfully.',
            data: new CompanyResource($company->load('settings')),
        );
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $updated = $this->companies->update($company, $request->validated());

        return ApiResponse::success(
            message: 'Company updated successfully.',
            data: new CompanyResource($updated->load('settings')),
        );
    }

    /**
     * Deactivate a company.
     *
     * Deactivation rather than deletion: future journals and ledger entries
     * will reference this row, and removing it would destroy the audit trail.
     */
    public function deactivate(Request $request, Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $deactivated = $this->companies->deactivate($company);

        return ApiResponse::success(
            message: 'Company deactivated successfully.',
            data: new CompanyResource($deactivated->load('settings')),
        );
    }

    public function activate(Request $request, Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        $activated = $this->companies->activate($company);

        return ApiResponse::success(
            message: 'Company activated successfully.',
            data: new CompanyResource($activated->load('settings')),
        );
    }

    /**
     * Make a company the caller's default.
     */
    public function setDefault(ManageCompanyMemberRequest $request, Company $company): JsonResponse
    {
        $user = $request->user();

        $this->companies->makeDefault($user, $company);

        return ApiResponse::success(
            message: 'Default company updated successfully.',
            data: new CompanyResource($company->load('settings')),
        );
    }

    /**
     * Add a user to this company.
     */
    public function addMember(ManageCompanyMemberRequest $request, Company $company): JsonResponse
    {
        $user = User::findOrFail((int) $request->validated('user_id'));

        $this->companies->attach(
            $company,
            $user,
            isDefault: $request->boolean('is_default'),
        );

        return ApiResponse::success(
            message: 'User added to the company successfully.',
            data: [
                'company_id' => $company->id,
                'user_id' => $user->id,
                'is_default' => $request->boolean('is_default'),
            ],
            status: 201,
        );
    }

    public function removeMember(Request $request, Company $company, User $user): JsonResponse
    {
        $this->authorize('update', $company);

        if (! $company->hasMember($user)) {
            return ApiResponse::error(
                message: 'This user does not belong to the selected company.',
                status: 404,
            );
        }

        $this->companies->detach($company, $user);

        return ApiResponse::success(message: 'User removed from the company successfully.');
    }
}
