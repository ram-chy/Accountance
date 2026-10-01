<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A purchase bill.
 *
 * The mirror of SalesInvoiceResource: company_id omitted, settlement figures
 * attached by SettlementService rather than recomputed here, and absent rather
 * than null when they were not attached.
 */
class PurchaseBillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bill_number' => $this->bill_number,

            'supplier_id' => $this->supplier_id,
            'bill_date' => $this->bill_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),

            'status' => $this->status->value,

            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'grand_total' => $this->grand_total,

            ...$this->settlementPayload(),

            'tax_account_id' => $this->tax_account_id,
            'journal_id' => $this->journal_id,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'notes' => $this->notes,

            'lines' => PurchaseBillLineResource::collection($this->whenLoaded('lines')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settlementPayload(): array
    {
        if (! $this->resource->hasSettlement()) {
            return [];
        }

        return [
            'paid_total' => $this->resource->paidTotalAmount()->toDatabase(),
            'balance_due' => $this->resource->balanceDueAmount()->toDatabase(),
            'is_overdue' => $this->resource->isOverdue(),
            'days_overdue' => $this->resource->daysOverdue(),
        ];
    }
}
