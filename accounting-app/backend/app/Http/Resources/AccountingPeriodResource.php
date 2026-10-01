<?php

namespace App\Http\Resources;

use App\Models\AccountingPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountingPeriod
 */
class AccountingPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status->value,
            /*
             * Whether this period currently accepts postings. Derived from the
             * status rather than stored, and stated plainly because it is the one
             * question a client has about a period before trying to post.
             */
            'accepts_postings' => $this->status->isOpen(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
