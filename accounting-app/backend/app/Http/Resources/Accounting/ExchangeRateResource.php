<?php

namespace App\Http\Resources\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_currency_id' => $this->from_currency_id,
            'from_currency' => $this->whenLoaded('fromCurrency', fn () => new CurrencyResource($this->fromCurrency)),
            'to_currency_id' => $this->to_currency_id,
            'to_currency' => $this->whenLoaded('toCurrency', fn () => new CurrencyResource($this->toCurrency)),
            'effective_date' => $this->effective_date?->toDateString(),
            'rate' => (string) $this->rate,
            'is_active' => $this->is_active,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
