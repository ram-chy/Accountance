<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier.
 *
 * The counterpart of CustomerResource, and for the same reasons it omits
 * company_id and any balance figure: the payable is the AP account's ledger
 * balance, and the "what do we still owe this supplier" sum is
 * SettlementService's definition, not something to re-decide in a resource.
 */
class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_code' => $this->supplier_code,
            'name' => $this->name,

            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country_code' => $this->country_code,
            'tax_identifier' => $this->tax_identifier,

            'payable_account_id' => $this->payable_account_id,
            'is_active' => $this->is_active,

            'payable_account' => new AccountResource($this->whenLoaded('payableAccount')),

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
