<?php

namespace App\Http\Resources;

use App\Models\BudgetLine;
use App\Models\BudgetLineDimension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A budget line: a planned amount for one account in one period.
 *
 * `amount` is emitted as the canonical 4-decimal string via the model's Money
 * accessor, never as a float, so a client subtracting plans from actuals never
 * meets the binary rounding this application forbids everywhere else.
 *
 * The account and period are emitted as bare ids AND, when loaded, as nested
 * objects, matching the fixed-asset category precedent: ids are what an edit
 * round-trips, objects are what a list renders without an extra request.
 *
 * @mixin BudgetLine
 */
class BudgetLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'budget_id' => $this->budget_id,
            'account_id' => $this->account_id,
            'accounting_period_id' => $this->accounting_period_id,
            'amount' => (string) $this->amount(),
            'description' => $this->description,

            'account' => new AccountResource($this->whenLoaded('account')),
            'accounting_period' => new AccountingPeriodResource($this->whenLoaded('accountingPeriod')),

            /*
             * Phase 17: the analytical labels on the plan line, one value per
             * dimension type. Empty when the line carries no labels.
             */
            'dimensions' => $this->whenLoaded(
                'budgetLineDimensions',
                fn () => $this->budgetLineDimensions->map(
                    fn (BudgetLineDimension $link) => [
                        'dimension_id' => (int) $link->financial_dimension_id,
                        'value_id' => (int) $link->financial_dimension_value_id,
                    ]
                )->values(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
