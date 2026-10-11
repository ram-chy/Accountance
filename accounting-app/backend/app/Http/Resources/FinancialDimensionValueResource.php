<?php

namespace App\Http\Resources;

use App\Models\FinancialDimensionValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One value of a financial dimension - "CC-001 Sales" under COST_CENTER.
 *
 * `financial_dimension_id` is always present because every value route is
 * addressed under its dimension and a client round-trips the parent on an edit.
 *
 * @mixin FinancialDimensionValue
 */
class FinancialDimensionValueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'financial_dimension_id' => $this->financial_dimension_id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
