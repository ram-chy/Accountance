<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a purchase bill.
 *
 * The mirror of SalesInvoiceLineResource. The only differences are the price
 * field (unit_cost) and the per-line account, which is an expense rather than
 * revenue.
 */
class PurchaseBillLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'description' => $this->description,

            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'discount' => $this->discount,
            'tax_rate' => $this->tax_rate,

            'line_total' => $this->line_total,
            'tax_amount' => $this->tax_amount,

            'expense_account_id' => $this->expense_account_id,
            'expense_account' => new AccountResource($this->whenLoaded('expenseAccount')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
