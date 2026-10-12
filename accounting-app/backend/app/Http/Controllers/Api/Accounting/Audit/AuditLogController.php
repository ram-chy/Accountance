<?php

namespace App\Http\Controllers\Api\Accounting\Audit;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Audit\AuditLogResource;
use App\Models\AuditLog;
use App\Services\CompanyContext;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext)
    {
        $this->authorizeResource(AuditLog::class, 'auditLog');
    }

    public function index(Request $request)
    {
        $company = $this->companyContext->getOrFail();
        $query = AuditLog::query()
            ->where('company_id', $company->getKey())
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('actor_id')) {
            $query->where('actor_id', (int) $request->input('actor_id'));
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', $request->input('auditable_type'));
        }

        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', (int) $request->input('auditable_id'));
        }

        if ($request->filled('request_id')) {
            $query->where('request_id', $request->input('request_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        return AuditLogResource::collection($query->paginate($request->input('per_page', 50)));
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        $auditLog->load('actor');

        return new AuditLogResource($auditLog);
    }

    public function financial(Request $request)
    {
        $company = $this->companyContext->getOrFail();
        $query = AuditLog::query()
            ->where('company_id', $company->getKey())
            ->whereNotNull('action')
            ->whereIn('action', AuditAction::financialValues())
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return AuditLogResource::collection($query->paginate($request->input('per_page', 50)));
    }

    public function security(Request $request)
    {
        $company = $this->companyContext->getOrFail();
        $query = AuditLog::query()
            ->where('company_id', $company->getKey())
            ->whereNotNull('action')
            ->whereIn('action', AuditAction::securityValues())
            ->with('actor')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return AuditLogResource::collection($query->paginate($request->input('per_page', 50)));
    }
}
