<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One allocation of a customer receipt to a sales invoice.
 *
 * The invoice is included when loaded so a receipt list can render "INV-000004"
 * without a second round trip per row. company_id is omitted on both sides: the
 * whole document is company-scoped already, and repeating it on every nested
 * object is noise.
 */
class CustomerReceiptAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_receipt_id' => $this->customer_receipt_id,
            'sales_invoice_id' => $this->sales_invoice_id,
            'amount' => $this->amount,

            'sales_invoice' => new SalesInvoiceResource($this->whenLoaded('invoice')),
        ];
    }
}
