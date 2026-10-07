<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Enums\ControlStatus;
use App\Http\Controllers\Controller;
use App\Services\Accounting\Controls\AccountingControlService;
use App\Services\Accounting\Controls\ControlFinding;
use App\Services\CompanyContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The accounting control report for the active company.
 *
 * Read-only by construction: the service only queries, and every finding states what
 * a check observed rather than offering a fix. An operator who sees a Fail decides
 * what to do about it through the ordinary endpoints; this route cannot repair
 * anything on its own.
 *
 * Findings are grouped by control in the response so a client can render one card
 * per control without re-grouping, and a `summary` count is included so "is anything
 * wrong" is answerable without walking the list.
 */
class AccountingControlController extends Controller
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly AccountingControlService $controls,
    ) {}

    public function index(): JsonResponse
    {
        $company = $this->context->getOrFail();

        $this->authorize('viewControls', $company);

        $findings = $this->controls->run($company);

        return ApiResponse::success(
            message: 'Accounting controls evaluated successfully.',
            data: [
                'company_id' => $company->getKey(),
                'base_currency' => $company->currency?->code,
                'summary' => $this->summarise($findings),
                'findings' => array_map(fn (ControlFinding $finding) => $finding->toArray(), $findings),
            ],
        );
    }

    /**
     * Count findings by status, so a caller can branch on `summary.fail > 0`.
     *
     * @param  list<ControlFinding>  $findings
     * @return array<string, int>
     */
    private function summarise(array $findings): array
    {
        $summary = [
            ControlStatus::Pass->value => 0,
            ControlStatus::Warning->value => 0,
            ControlStatus::Fail->value => 0,
        ];

        foreach ($findings as $finding) {
            $summary[$finding->status->value]++;
        }

        return $summary;
    }
}
