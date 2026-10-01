<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a sales invoice.
 *
 * line_total and tax_amount are stored columns, not values computed here. They
 * are written by DocumentCalculator at draft time and recalculated from the lines
 * again at posting time, so the persisted figures and the figures a client sees
 * are the same numbers the journal was built from.
 */
class SalesInvoiceLineResource extends JsonResource
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
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'tax_rate' => $this->tax_rate,

            'line_total' => $this->line_total,
            'tax_amount' => $this->tax_amount,

            'revenue_account_id' => $this->revenue_account_id,
            'revenue_account' => new AccountResource($this->whenLoaded('revenueAccount')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
