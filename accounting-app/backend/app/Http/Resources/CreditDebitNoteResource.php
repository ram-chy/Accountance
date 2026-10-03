<?php

namespace App\Http\Resources;

use App\Enums\NoteType;
use App\Models\CreditDebitNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A credit or debit note.
 *
 * company_id is omitted, following SalesInvoiceResource and JournalResource: every
 * transactional endpoint is already scoped to the active company, so echoing the id
 * tells the client nothing it did not send and invites it to be fed back as input
 * by an endpoint that should be reading the context.
 *
 * source_document_type and source_document_id are the derived uniform pair
 * described in the notes table migration. The table stores two nullable foreign
 * keys so that a real FK constraint can exist at all - with a (type, id) pair the
 * database could enforce nothing beyond "type is one of two strings" - and this
 * resource is where that storage decision is hidden from clients. Both names are
 * also present individually below, because a client building an edit form needs to
 * send back the field it was given.
 *
 * NO ADJUSTED TOTALS, NO REMAINING AMOUNT
 *
 * There is no adjusted_total or remaining_adjustable_amount key here, because
 * neither is stored and neither is attached the way SettlementService attaches
 * paid_total to an invoice. They are sums over posted notes, computed on demand by
 * CreditDebitNoteAdjustmentService, and a note response carrying a cached copy of a
 * sum would be a second source of truth that could disagree with the notes
 * themselves. The two endpoints that need those figures - the note's own source
 * document and its adjustable lines - compute them per request and attach them
 * there, where they are fresh.
 *
 * @mixin CreditDebitNote
 */
class CreditDebitNoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note_number' => $this->note_number,

            /*
             * The kind of adjustment, as the enum's value. The note_type is the one
             * field a client must read before it can interpret anything else here:
             * it decides the tax side, the account role on each line, the direction
             * of the journal entry and the sign the note contributes to the
             * document it adjusts. `is_credit` is exposed alongside it because
             * "does this reduce what the counterparty owes" is the question most
             * callers actually have, and answering it client-side would mean
             * reimplementing NoteType::isCredit() in every consumer.
             */
            'note_type' => $this->note_type->value,
            'is_credit' => $this->note_type->isCredit(),
            'label' => $this->note_type->label(),

            'note_date' => $this->note_date->toDateString(),

            'status' => $this->status->value,

            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'tax_total' => $this->tax_total,
            'grand_total' => $this->grand_total,

            'reason' => $this->reason,
            'reference' => $this->reference,
            'notes' => $this->notes,

            /*
             * The derived uniform pair, plus the two concrete columns underneath.
             * See the class docblock for why both are present.
             */
            'source_document_type' => $this->sourceDocumentType(),
            'source_document_id' => $this->sourceDocumentId(),
            'source_document_number' => $this->sourceDocumentNumber(),

            'sales_invoice_id' => $this->sales_invoice_id,
            'purchase_bill_id' => $this->purchase_bill_id,

            /*
             * The counterparty. One of these is always null and the other always set
             * - the counterparty_check constraint in the migration says so - so a
             * client can branch on note_type and read the id it expects without
             * testing both.
             */
            'customer_id' => $this->customer_id,
            'supplier_id' => $this->supplier_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),

            'tax_account_id' => $this->tax_account_id,
            'journal_id' => $this->journal_id,
            'posted_at' => $this->posted_at?->toIso8601String(),

            'lines' => CreditDebitNoteLineResource::collection($this->whenLoaded('lines')),

            'created_by' => $this->created_by,
            'posted_by' => $this->posted_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function sourceDocumentType(): string
    {
        return $this->note_type->isSales() ? 'sales_invoice' : 'purchase_bill';
    }

    /**
     * The adjusted document's id, from whichever of the two columns is set.
     *
     * Read off the columns rather than through the sourceDocument() relation and
     * then taking its key, because the columns are already on the row: going out
     * through the relation to read a value that was loaded as part of selecting
     * the note would be a round trip's worth of work to arrive back where the
     * answer already was. One of the two is always non-null - the note's own
     * source_check constraint says so - so this is not a guess about which world
     * the note belongs to, only a lookup of which column holds it.
     */
    private function sourceDocumentId(): int
    {
        return $this->sales_invoice_id ?? $this->purchase_bill_id;
    }

    /**
     * The adjusted document's number, or null when the relation was not loaded.
     *
     * Unlike the id above, the number lives on the other table and so has to come
     * through the relation. Null therefore means "this response did not include the
     * document's number", which is why every note endpoint eager-loads its source:
     * a client never has to distinguish the two cases, because it is never handed
     * one. Follows SalesInvoiceResource in reporting the figure it can actually
     * reach and no other.
     */
    private function sourceDocumentNumber(): ?string
    {
        return match ($this->note_type) {
            NoteType::SalesCreditNote, NoteType::SalesDebitNote => $this->salesInvoice?->invoice_number,
            NoteType::PurchaseCreditNote, NoteType::PurchaseDebitNote => $this->purchaseBill?->bill_number,
        };
    }
}
