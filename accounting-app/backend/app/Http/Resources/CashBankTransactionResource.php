<?php

namespace App\Http\Resources;

use App\Models\CashBankTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cash/bank transaction.
 *
 * What is deliberately missing is more interesting than what is present: there
 * is no balance, no running balance and no debit/credit total. A client that
 * wants the effect of this transaction on an account's money asks
 * GET /api/accounting/reports/cash-bank or the general ledger, and both derive
 * their figures from posted journal lines. Emitting a balance here would invite
 * a client to display a number this record cannot be responsible for - and would
 * be a number nobody maintains, because no code path computes one.
 *
 * The two accounts are loaded as full AccountResources rather than as bare ids
 * because the whole point of a transfer is the pair of accounts it moves between,
 * and a client rendering it would otherwise have to make two further requests to
 * show "1000 Cash -> 1200 HDFC". The journal is loaded only after posting, where
 * it is what makes the transaction traceable to the entry that produced it.
 *
 * @mixin CashBankTransaction
 */
class CashBankTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'transaction_type' => $this->transaction_type->value,
            'transaction_date' => $this->transaction_date->toDateString(),

            'source_account_id' => $this->source_account_id,
            'destination_account_id' => $this->destination_account_id,

            /*
             * The raw DECIMAL(20,4) string, matching every other monetary field
             * in this API. Cast to a float or to a number here and a client
             * would silently round 1175.2500; the string is the only lossless
             * representation, and it is what the reports also return.
             */
            'amount' => $this->amount,
            'status' => $this->status->value,

            'reference' => $this->reference,
            'notes' => $this->notes,

            'source_account' => new AccountResource($this->whenLoaded('sourceAccount')),
            'destination_account' => new AccountResource($this->whenLoaded('destinationAccount')),
            'journal' => new JournalResource($this->whenLoaded('journal')),

            /*
             * The journal link, which is what makes the transaction traceable to
             * the accounting record. Null while the transaction is a draft, and
             * its presence is the only indicator of "has this moved money yet"
             * that does not require reading the status as well.
             */
            'journal_id' => $this->journal_id,
            'posted_at' => $this->posted_at?->toIso8601String(),

            'created_by' => $this->created_by,
            'posted_by' => $this->posted_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
