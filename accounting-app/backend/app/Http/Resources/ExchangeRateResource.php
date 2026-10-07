<?php

namespace App\Http\Resources;

use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExchangeRate
 */
class ExchangeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_currency_id' => $this->from_currency_id,
            'to_currency_id' => $this->to_currency_id,
            /*
             * The pair as a direction string and the codes it is built from, so a
             * client never has to resolve two currency ids to render "USD/INR". The
             * relation is loaded only when it was eager-loaded; the raw ids are
             * always present.
             */
            'from_currency' => $this->whenLoaded('fromCurrency', fn () => $this->fromCurrency->code),
            'to_currency' => $this->whenLoaded('toCurrency', fn () => $this->toCurrency->code),
            'pair' => $this->whenLoaded('fromCurrency', fn () => $this->pair()),
            'effective_date' => $this->effective_date?->toDateString(),
            /*
             * The stored rate as the decimal string the column holds, not a float.
             * Sending a float would invite the client to do arithmetic whose result
             * must match the ledger's DECIMAL math exactly - which is the invariant
             * the journal_lines CHECK enforces.
             */
            'rate' => $this->rate,
            'is_active' => $this->is_active,
            'source' => $this->source,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
