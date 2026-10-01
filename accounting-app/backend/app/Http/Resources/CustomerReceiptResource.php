<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer receipt.
 *
 * amount is a client-supplied input, not a derived figure - the money arrived and
 * someone stated how much - so unlike an invoice there is no subtotal/tax/
 * grand_total triple. What makes it trustworthy is that the allocations are
 * required to total exactly this amount, and that a draft cannot be posted
 * without them; the sum is therefore always this number, and emitting a
 * computed "allocated_total" would only ever restate it.
 *
 * The allocations are loaded for show and index, because a receipt without them
 * is not reviewable: the whole point of the document is which invoices it
 * discharges.
 */
class CustomerReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,

            'customer_id' => $this->customer_id,
            'receipt_date' => $this->receipt_date->toDateString(),

            'amount' => $this->amount,
            'status' => $this->status->value,

            'payment_account_id' => $this->payment_account_id,
            'reference' => $this->reference,
            'notes' => $this->notes,

            'allocations' => CustomerReceiptAllocationResource::collection($this->whenLoaded('allocations')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'payment_account' => new AccountResource($this->whenLoaded('paymentAccount')),
            'journal' => new JournalResource($this->whenLoaded('journal')),

            'journal_id' => $this->journal_id,
            'posted_at' => $this->posted_at?->toIso8601String(),

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
