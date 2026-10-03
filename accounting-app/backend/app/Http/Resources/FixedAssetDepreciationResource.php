<?php

namespace App\Http\Resources;

use App\Models\FixedAssetDepreciation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One posted depreciation charge.
 *
 * The period is emitted by its NUMBER and its date range rather than by a month
 * name, because that is what identifies it: period 1 of an asset capitalised in
 * February runs from the depreciation start date to the equivalent date a month
 * later, and its boundaries are the ones the service used to decide whether the
 * period had elapsed. Re-deriving them at display time would be a second
 * implementation of that rule.
 *
 * There is no running balance column. A charge's effect on the register is the sum
 * of the charges up to it, which is a property of the sequence rather than of the
 * row, and a per-row balance would be one more derived figure to keep in step.
 *
 * @mixin FixedAssetDepreciation
 */
class FixedAssetDepreciationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fixed_asset_id' => $this->fixed_asset_id,
            'period_number' => $this->period_number,
            'period_start_date' => $this->period_start_date->toDateString(),
            'period_end_date' => $this->period_end_date->toDateString(),

            'amount' => $this->amount,
            'is_final_period' => $this->isFinalPeriod(),

            'journal_id' => $this->journal_id,
            'journal' => new JournalResource($this->whenLoaded('journal')),

            'posted_at' => $this->posted_at?->toIso8601String(),
            'posted_by' => $this->posted_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
