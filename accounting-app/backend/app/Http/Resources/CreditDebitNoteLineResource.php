<?php

namespace App\Http\Resources;

use App\Models\CreditDebitNoteLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of a credit or debit note.
 *
 * The monetary columns are stored values, not values computed here. They are
 * written by DocumentCalculator at draft time and recalculated from the lines again
 * at posting time, so what a client reads is the same figure the journal was built
 * from - following SalesInvoiceLineResource for the same reason.
 *
 * `source_document_type` and `source_line_id` are DERIVED, and this is the one
 * place the storage shape is smoothed over. The table has two nullable FKs rather
 * than a type/id pair, so that a foreign key can actually constrain the reference;
 * a client, though, should not have to know that. It receives one uniform field
 * name whichever kind of line it is, and `null` when the adjustment is
 * document-level - which is a meaningful answer, not a missing one.
 *
 * @mixin CreditDebitNoteLine
 */
class CreditDebitNoteLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_number' => $this->line_number,
            'description' => $this->description,

            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount' => $this->discount,
            'tax_rate' => $this->tax_rate,

            'line_total' => $this->line_total,
            'tax_amount' => $this->tax_amount,

            /*
             * tax_id is the configured tax this line was charged with, snapshotted
             * at draft time. Null for a line carrying only a hand-entered rate, and
             * for a line carrying several taxes - see DocumentCalculator::taxOn,
             * which reports a multi-tax line as unattributed rather than
             * attributing it to one of several.
             */
            'tax_id' => $this->tax_id,

            /*
             * One account column for both worlds: the revenue account for a sales
             * note line, the expense account for a purchase one. The role is decided
             * by the note's type rather than chosen per line, so a client reading
             * this needs the note to know which it is - which is why `account_id`
             * is exposed alongside the note's type and not instead of it.
             */
            'account_id' => $this->account_id,
            'account' => new AccountResource($this->whenLoaded('account')),

            /*
             * `source_document_type` answers "which document was adjusted", which the
             * line cannot know on its own - it depends entirely on the note it hangs
             * off. `source_line_id` is the other half of the same derivation: which
             * line of that document, or null for a document-level adjustment. The
             * two are derived together in sourceReference() below because they are
             * one piece of storage smoothing, not two independent facts.
             *
             * Note the deliberate absence of source_line_no. A client that needs to
             * label the credit with the invoice line it reverses can look the number
             * up from the adjustable-lines endpoint, which reports it per source
             * line. Repeating it here would denormalise a second copy of a
             * cross-table reference onto the row.
             */
            'source_document_type' => $this->sourceReference()['document_type'],
            'source_line_id' => $this->sourceReference()['line_id'],

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The single source reference, flattened into the response's two fields.
     *
     * Called twice from toArray() and so written as one derivation rather than two
     * independent helpers: source_document_type and source_line_id are one fact seen
     * from two angles, and computing them separately would leave room for them to
     * disagree. `$this->resource` is the line itself - a nested resource is
     * constructed per line and is not handed its parent - so the note has to be
     * loaded off the line, which the controller does in one query for the whole note.
     *
     * @return array{document_type: string|null, line_id: int|null}
     */
    private function sourceReference(): array
    {
        $note = $this->resource->note;

        if (! $note) {
            return ['document_type' => null, 'line_id' => null];
        }

        return [
            'document_type' => $note->note_type->isSales() ? 'sales_invoice' : 'purchase_bill',
            'line_id' => $this->resource->sales_invoice_line_id
                ?? $this->resource->purchase_bill_line_id,
        ];
    }
}
