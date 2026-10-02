<?php

namespace App\Http\Resources;

use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tax
 */
class TaxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'tax_type' => $this->tax_type->value,
            'calculation_basis' => $this->calculation_basis->value,
            'is_active' => $this->is_active,
            /*
             * Whether this tax may be used on each side, as a derived fact rather
             * than requiring the client to interpret tax_type itself. "BOTH" is the
             * case that makes this worth sending: a client that checks
             * `tax_type == 'OUTPUT'` gets BOTH taxes wrong.
             */
            'applies_to_sales' => $this->appliesToSales(),
            'applies_to_purchase' => $this->appliesToPurchase(),
            /*
             * The current rate as a percentage, and null when the tax has no rate
             * covering today. Exposed because a configuration screen has to show
             * what a tax charges now, and the client cannot answer that from a tax
             * alone without walking every rate row and comparing dates itself.
             */
            'current_rate' => $this->whenLoaded('rates', fn () => $this->currentRate()?->rate),
            'account_mapping' => new TaxAccountMappingResource($this->whenLoaded('accountMapping')),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
