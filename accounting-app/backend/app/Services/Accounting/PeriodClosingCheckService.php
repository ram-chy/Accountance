<?php

namespace App\Services\Accounting;

use App\Enums\ControlStatus;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Services\Accounting\Controls\AccountingControlService;
use App\Services\Accounting\Controls\ControlFinding;

/**
 * The period-end review: the question "may this period be closed?" asked without
 * closing anything.
 *
 * It is a thin composition, on purpose. The findings come from
 * AccountingControlService::forPeriod(), so the review and the general control
 * report are the same checks seen through two scopes - adding a check to the
 * service makes it part of every review, and this class never re-implements one.
 * What it adds is only the closing decision: which findings block a close, which
 * merely warn, and a summary a caller can branch on.
 *
 * READ-ONLY. Like the control service it builds on, nothing here writes,
 * repairs or transitions anything. AccountingPeriodService::close() calls
 * review() and acts on the answer; the review itself has no side effect, which is
 * what lets the same method back both a GET endpoint and the close guard.
 */
class PeriodClosingCheckService
{
    public function __construct(
        private readonly AccountingControlService $controls,
    ) {}

    /**
     * Evaluate one period's eligibility for closure.
     *
     * @return array{
     *     period: array<string, mixed>,
     *     eligible: bool,
     *     already_closed: bool,
     *     summary: array<string, int>,
     *     blocking_findings: list<array<string, mixed>>,
     *     warning_findings: list<array<string, mixed>>,
     *     findings: list<array<string, mixed>>,
     *     required_action: ?string
     * }
     */
    public function review(Company $company, AccountingPeriod $period): array
    {
        $findings = $this->controls->forPeriod($company, $period);

        $blocking = $this->withStatus($findings, ControlStatus::Fail);
        $warnings = $this->withStatus($findings, ControlStatus::Warning);

        $alreadyClosed = $period->status->isClosed();
        $eligible = ! $alreadyClosed && $blocking === [];

        return [
            'period' => [
                'id' => (int) $period->getKey(),
                'name' => $period->name,
                'start_date' => $period->start_date?->toDateString(),
                'end_date' => $period->end_date?->toDateString(),
                'status' => $period->status->value,
                'financial_year_id' => $period->financial_year_id === null ? null : (int) $period->financial_year_id,
            ],
            'eligible' => $eligible,
            'already_closed' => $alreadyClosed,
            'summary' => $this->summarise($findings),
            'blocking_findings' => array_map($this->serialise(...), $blocking),
            'warning_findings' => array_map($this->serialise(...), $warnings),
            'findings' => array_map($this->serialise(...), $findings),
            'required_action' => $this->requiredAction($alreadyClosed, $blocking),
        ];
    }

    /**
     * The sentence a caller can show when a close is refused, or null when the
     * period is ready. Kept here rather than in the controller so the API message
     * and the service's ValidationException explain the same thing.
     *
     * @param  list<ControlFinding>  $blocking
     */
    public function requiredAction(bool $alreadyClosed, array $blocking): ?string
    {
        if ($alreadyClosed) {
            return 'This period is already closed.';
        }

        if ($blocking === []) {
            return null;
        }

        return sprintf(
            'Resolve the %d blocking control finding(s) before closing this period.',
            count($blocking),
        );
    }

    /**
     * @param  list<ControlFinding>  $findings
     * @return list<ControlFinding>
     */
    private function withStatus(array $findings, ControlStatus $status): array
    {
        return array_values(array_filter(
            $findings,
            fn (ControlFinding $finding) => $finding->status === $status,
        ));
    }

    /**
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

    /**
     * @return array<string, mixed>
     */
    private function serialise(ControlFinding $finding): array
    {
        return $finding->toArray();
    }
}
