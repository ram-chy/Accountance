<?php

namespace App\Http\Resources;

use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankReconciliationMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalLine $line */
        $line = $this->resource;

        $debit = $line->debitAmount();
        $credit = $line->creditAmount();
        $amount = $debit->isGreaterThanZero() ? $debit : $credit;
        $direction = $debit->isGreaterThanZero() ? 'DEBIT' : ($credit->isGreaterThanZero() ? 'CREDIT' : 'ZERO');

        return [
            'journal_id' => $line->journal_id,
            'journal_line_id' => $line->getKey(),
            'journal_number' => $line->journal?->journal_number,
            'journal_date' => $line->journal?->date?->toDateString(),
            'reference' => $line->journal?->reference,
            'description' => $line->journal?->description,
            'source_type' => $line->journal?->source_type,
            'source_id' => $line->journal?->source_id,
            'debit' => $debit->toString(),
            'credit' => $credit->toString(),
            'amount' => $amount->toString(),
            'direction' => $direction,
            'cleared' => (bool) $line->getAttribute('cleared'),
        ];
    }
}
