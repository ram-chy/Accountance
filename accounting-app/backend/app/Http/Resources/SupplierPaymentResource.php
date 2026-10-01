<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier payment.
 *
 * The mirror of CustomerReceiptResource: amount is a client input, and the
 * allocations are required to total it exactly.
 */
class SupplierPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,

            'supplier_id' => $this->supplier_id,
            'payment_date' => $this->payment_date->toDateString(),

            'amount' => $this->amount,
            'status' => $this->status->value,

            'payment_account_id' => $this->payment_account_id,
            'reference' => $this->reference,
            'notes' => $this->notes,

            'allocations' => SupplierPaymentAllocationResource::collection($this->whenLoaded('allocations')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
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
