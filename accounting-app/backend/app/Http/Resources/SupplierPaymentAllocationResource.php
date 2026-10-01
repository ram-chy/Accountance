<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One allocation of a supplier payment to a purchase bill.
 *
 * The mirror of CustomerReceiptAllocationResource.
 */
class SupplierPaymentAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_payment_id' => $this->supplier_payment_id,
            'purchase_bill_id' => $this->purchase_bill_id,
            'amount' => $this->amount,

            'purchase_bill' => new PurchaseBillResource($this->whenLoaded('bill')),
        ];
    }
}
