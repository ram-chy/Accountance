<?php

namespace App\Enums;

/**
 * Budget lifecycle.
 *
 * Two states only, and the same two shapes the rest of the application uses
 * (PeriodStatus, FinancialYearStatus, TransactionStatus): a record is a draft
 * until it is finalized. There is deliberately no PENDING_APPROVAL, LOCKED,
 * ARCHIVED or SUPERSEDED. The brief lists exactly those as states not to invent
 * without a demonstrated requirement, and this application has none: a budget is
 * approved in one act, an approved budget becomes historical by being *revised
 * into a new draft version* rather than by moving to a state on its own row, and
 * a discarded draft is deleted rather than archived.
 *
 * Approved budgets are immutable through the API. A change to an approved plan
 * is a new version (Budget::version_number + 1, parent_budget_id pointing back),
 * which is what preserves the approved figures instead of rewriting them.
 */
enum BudgetStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
