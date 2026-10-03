<?php

namespace App\Http\Resources;

use App\Models\FixedAssetDisposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fixed asset disposal.
 *
 * The gain and the loss are emitted as two separate non-negative amounts, matching
 * how they are stored and how the journal consumes them, AND as one signed
 * `net_result` for the arithmetic a reader actually wants. The two forms are not
 * redundant: a table of disposals wants to sum a signed column, and the journal
 * needed to know which direction the entry ran. Stating both saves every client from
 * re-deriving the sign with a convention it might get backwards.
 *
 * `carrying_value_at_disposal` is the figure on the date of disposal, frozen when
 * the entry posted. It is NOT recomputed from the asset's current depreciation rows
 * - those may since have grown if the asset were somehow charged again, and the
 * point of storing it is that the disposal's arithmetic is a fact about the past.
 *
 * @mixin FixedAssetDisposal
 */
class FixedAssetDisposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fixed_asset_id' => $this->fixed_asset_id,
            'disposal_date' => $this->disposal_date->toDateString(),
            'reason' => $this->reason,

            'proceeds' => $this->proceeds,
            'carrying_value_at_disposal' => $this->carrying_value_at_disposal,
            'gain' => $this->gain,
            'loss' => $this->loss,
            'net_result' => $this->netResultAmount()->toDatabase(),

            'proceeds_account_id' => $this->proceeds_account_id,
            'proceeds_account' => new AccountResource($this->whenLoaded('proceedsAccount')),

            'journal_id' => $this->journal_id,
            'journal' => new JournalResource($this->whenLoaded('journal')),

            'posted_at' => $this->posted_at?->toIso8601String(),
            'disposed_by' => $this->disposed_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
