<?php

namespace App\Http\Resources;

use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalLine
 */
class JournalLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'account_id' => $this->account_id,
            'account_code' => $this->whenLoaded('account', fn () => $this->account?->code),
            'account_name' => $this->whenLoaded('account', fn () => $this->account?->name),
            'description' => $this->description,
            'debit' => (string) $this->debit,
            'credit' => (string) $this->credit,

            /*
             * Phase 14. debit/credit above are always base currency; these four
             * record what the line was transacted in. Null currency_id means the
             * line is base-currency, so the foreign fields and the rate are null
             * with it - a rate without an amount is not a fact about a line.
             */
            'currency_id' => $this->currency_id,
            'currency_code' => $this->whenLoaded('currency', fn () => $this->currency?->code),
            'exchange_rate' => $this->exchange_rate,
            'foreign_debit' => $this->foreign_debit === null ? null : (string) $this->foreign_debit,
            'foreign_credit' => $this->foreign_credit === null ? null : (string) $this->foreign_credit,
        ];
    }
}
