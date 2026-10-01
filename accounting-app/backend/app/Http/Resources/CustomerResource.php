<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer.
 *
 * Two omissions worth naming.
 *
 * No company_id, following JournalResource: the endpoint is already scoped to the
 * active company.
 *
 * No balance. The receivable is the AR account's ledger balance from posted
 * journals, and a customer's outstanding total is a sum over their posted
 * invoices. Emitting it here would mean a decision - is this the AR account
 * balance, or the sum of outstanding invoices, or the difference? - made in a
 * resource rather than in SettlementService, and the two can legitimately differ
 * when a customer has a manual journal against their AR account. The report
 * endpoint that needs it calls the service that owns the definition.
 *
 * is_active is present, because it is a column and it changes what a client may
 * attempt next.
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'name' => $this->name,

            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country_code' => $this->country_code,
            'tax_identifier' => $this->tax_identifier,

            'receivable_account_id' => $this->receivable_account_id,
            'is_active' => $this->is_active,

            'receivable_account' => new AccountResource($this->whenLoaded('receivableAccount')),

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
