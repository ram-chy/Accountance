<?php

namespace App\Http\Resources;

use App\Models\BankReconciliation;
use App\Services\Accounting\Reconciliation\BankReconciliationCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BankReconciliation $reconciliation */
        $reconciliation = $this->resource;

        $calculator = app(BankReconciliationCalculator::class);
        $summary = $calculator->summary($reconciliation);

        return [
            'id' => $reconciliation->getKey(),
            'company_id' => $reconciliation->company_id,
            'bank_account_id' => $reconciliation->bank_account_id,
            'account_id' => $reconciliation->account_id,
            'from_date' => $reconciliation->from_date?->toDateString(),
            'to_date' => $reconciliation->to_date?->toDateString(),
            'status' => $reconciliation->status->value,
            'statement_opening_balance' => $summary['statement_opening']->__toString(),
            'statement_closing_balance' => $summary['statement_closing']->__toString(),
            'ledger_opening' => $summary['ledger_opening']->__toString(),
            'ledger_closing' => $summary['ledger_closing']->__toString(),
            'period_debits' => $summary['period_debits']->__toString(),
            'period_credits' => $summary['period_credits']->__toString(),
            'cleared_debits' => $summary['cleared_debits']->__toString(),
            'cleared_credits' => $summary['cleared_credits']->__toString(),
            'uncleared_debits' => $summary['uncleared_debits']->__toString(),
            'uncleared_credits' => $summary['uncleared_credits']->__toString(),
            'reconciled_balance' => $summary['reconciled_balance']->__toString(),
            'difference' => $summary['difference']->__toString(),
            'total_movements' => $summary['total_movements'],
            'cleared_movements' => $summary['cleared_movements'],
            'uncleared_movements' => $summary['uncleared_movements'],
            'is_reconciled' => $reconciliation->status->isReconciled(),
            'previous_reconciled_closing' => $calculator->previousReconciledClosing($reconciliation)?->__toString(),
            'opening_balance_difference' => $calculator->openingBalanceDifference($reconciliation)?->__toString(),
            'notes' => $reconciliation->notes,
            'completed_at' => $reconciliation->completed_at?->toDateTimeString(),
            'reopened_at' => $reconciliation->reopened_at?->toDateTimeString(),
            'created_at' => $reconciliation->created_at?->toDateTimeString(),
            'updated_at' => $reconciliation->updated_at?->toDateTimeString(),
        ];
    }
}
