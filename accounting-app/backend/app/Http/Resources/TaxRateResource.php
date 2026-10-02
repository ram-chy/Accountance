<?php

namespace App\Http\Resources;

use App\Models\TaxRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxRate
 */
class TaxRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tax_id' => $this->tax_id,
            /*
             * Sent as the stored decimal string, never as a float. A JSON number
             * would arrive at the client as a double and 7.5000 could come back as
             * 7.4999999999999996; the string form round-trips exactly, which is the
             * only property that matters for a rate a client may send straight back.
             */
            'rate' => $this->rate,
            'effective_from' => $this->effective_from?->toDateString(),
            /*
             * null means the rate is still open-ended. Surfaced explicitly because
             * "no end date" and "ends today" are different facts and a client
             * rendering a rate list needs to tell them apart.
             */
            'effective_to' => $this->effective_to?->toDateString(),
            'is_open_ended' => $this->effective_to === null,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
