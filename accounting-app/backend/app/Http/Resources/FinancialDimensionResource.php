<?php

namespace App\Http\Resources;

use App\Enums\FinancialDimensionType;
use App\Models\FinancialDimension;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A financial dimension - one axis the ledger may be cut along.
 *
 * The shape is deliberately small: an id, the type that says what question the
 * dimension asks, the code and name a user picks, and whether it is currently
 * usable. Nothing server-owned beyond `is_active` is exposed as though it were
 * editable, and `company_id` is not echoed at all - a dimension is always read
 * through the caller's own company context, so repeating the id would only invite
 * a client to send it back.
 *
 * Values are emitted only when the controller has loaded them, so a dimension
 * list does not issue a query per row for a tree nobody asked for.
 *
 * @mixin FinancialDimension
 */
class FinancialDimensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => FinancialDimensionType::from($this->type)->label(),
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,

            'values' => FinancialDimensionValueResource::collection($this->whenLoaded('values')),
            'values_count' => $this->whenCounted('values'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
