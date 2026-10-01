<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A sales invoice.
 *
 * company_id is omitted, following JournalResource: every transactional endpoint
 * is already scoped to the active company, so echoing the id tells the client
 * nothing it did not send and invites it to be fed back as input by an endpoint
 * that should be reading the context.
 *
 * paid_total and balance_due are not recomputed here. They are sums over posted
 * allocations, and the controller attaches them via SettlementService before
 * serialising. A resource that did its own arithmetic would be a second
 * implementation of a rule no service owns, and the two would eventually disagree
 * in a way nothing would catch.
 *
 * When those figures were not attached - a create response, where nothing is
 * allocated yet - the keys are absent rather than null. "Paid 0.00" and "not yet
 * calculated" are different claims, and a client that cannot tell them apart will
 * eventually treat one as the other.
 */
class SalesInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,

            'customer_id' => $this->customer_id,
            'invoice_date' => $this->invoice_date->toDateString(),
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

            'lines' => SalesInvoiceLineResource::collection($this->whenLoaded('lines')),
            'customer' => new CustomerResource($this->whenLoaded('customer')),

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
