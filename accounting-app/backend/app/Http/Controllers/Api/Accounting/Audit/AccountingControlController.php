<?php

namespace App\Http\Controllers\Api\Accounting\Audit;

use App\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Resources\Accounting\Controls\ControlFindingResource;
use App\Models\AuditLog;
use App\Services\Accounting\Controls\AccountingControlService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountingControlController extends Controller
{
    public function __construct(private readonly AccountingControlService $controls) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);
        abort_unless($this->authorize(PermissionName::ControlsView->value), 403);

        $findings = $this->controls->runAll();

        return ControlFindingResource::collection($findings);
    }

    public function journal(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);
        abort_unless($this->authorize(PermissionName::ControlsView->value), 403);

        return ControlFindingResource::collection($this->controls->journalIntegrity());
    }

    public function period(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);
        abort_unless($this->authorize(PermissionName::ControlsView->value), 403);

        return ControlFindingResource::collection($this->controls->periodIntegrity());
    }

    public function references(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);
        abort_unless($this->authorize(PermissionName::ControlsView->value), 403);

        return ControlFindingResource::collection($this->controls->referenceIntegrity());
    }
}
