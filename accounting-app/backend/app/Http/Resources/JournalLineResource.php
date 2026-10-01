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
        ];
    }
}
