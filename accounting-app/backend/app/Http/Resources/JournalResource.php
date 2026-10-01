<?php

namespace App\Http\Resources;

use App\Models\Journal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Journal
 */
class JournalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lines = $this->whenLoaded('lines');

        $totals = $lines !== null
            ? [
                'total_debit' => (string) $this->totalDebit(),
                'total_credit' => (string) $this->totalCredit(),
                /*
                 * A client-visible confirmation of the invariant, derived on the
                 * server from the persisted lines. A draft can legitimately be
                 * unbalanced (the service rejects the ones that are, but the flag
                 * is cheap and unambiguous for a UI to render), so it is reported
                 * rather than used as a gate.
                 */
                'is_balanced' => $this->isBalanced(),
            ]
            : null;

        return [
            'id' => $this->id,
            'journal_number' => $this->journal_number,
            'journal_date' => $this->journal_date?->toDateString(),
            'description' => $this->description,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'source_type' => $this->source_type?->value,
            'source_id' => $this->source_id,
            'created_by' => $this->created_by,
            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            /*
             * company_id is intentionally omitted. Every accounting endpoint is
             * already scoped to one company, so echoing the id back tells the
             * client nothing it did not send and invites it to be sent back as
             * input by a later endpoint that should be using the context.
             */
            'lines' => JournalLineResource::collection($lines ?? []),
            'totals' => $totals,
        ];
    }
}
