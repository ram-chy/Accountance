<?php

namespace App\Models;

use App\Enums\NoteType;
use App\Enums\TransactionStatus;
use App\Support\Money;
use Database\Factories\CreditDebitNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    /*
     * company_id is NOT here, and neither are note_number, status, journal_id,
     * created_by, posted_by, posted_at, customer_id, supplier_id,
     * sales_invoice_id or purchase_bill_id.
     *
     * Every one of those is a server decision. The services write them with
     * forceFill() inside a transaction; a client cannot reach them because no
     * request rule validates them and no controller passes them through. The
     * fillable list therefore holds only the fields a user actually supplies.
     *
     * tax_account_id is fillable because the user chooses it on a form, exactly
     * as on an invoice - but it is still never enough on its own: the calculator
     * refuses a note that computes a non-zero tax with no tax account, and the
     * posting service re-resolves it.
     */
    'note_type',
    'note_date',
    'reason',
    'reference',
    'notes',
    'tax_account_id',
])]
class CreditDebitNote extends Model
{
    /** @use HasFactory<CreditDebitNoteFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'note_type' => NoteType::class,
            'status' => TransactionStatus::class,
            'note_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The invoice this note adjusts, for a sales note.
     *
     * Null for a purchase note. The single-source CHECK constraint guarantees
     * exactly one of the two source relations is ever populated, so a caller can
     * read whichever it knows it has without first testing both.
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * The bill this note adjusts, for a purchase note.
     */
    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    /**
     * Always populated for a sales note, always null for a purchase note.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Always populated for a purchase note, always null for a sales note.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditDebitNoteLine::class)->orderBy('line_number');
    }

    /**
     * Both source documents as one relation.
     *
     * Convenience for the code paths that genuinely do not care which they are -
     * chiefly CreditDebitNoteAdjustmentService's per-line sums and the source
     * deletion guard - and nothing else. Anything that branches on the kind of
     * adjustment reads note_type instead, because branching on which relation is
     * null is the encoding NoteType exists to replace.
     *
     * @return BelongsTo<SalesInvoice|PurchaseBill, $this>
     */
    public function sourceDocument(): BelongsTo
    {
        /*
         * The foreign key has to be named explicitly. Eloquent derives one from the
         * relation METHOD name, which would be `source_document_id` - a column that
         * does not exist and was deliberately not created. The two nullable FKs are
         * the shape the table has, so the key is chosen from the same
         * isSales() test that picks the class.
         */
        return $this->note_type->isSales()
            ? $this->salesInvoice()
            : $this->purchaseBill();
    }

    /**
     * The source document's primary key, or null on an unsaved note.
     *
     * Exposed so a caller can say "note 12 adjusts document 5" without loading
     * the document to find out.
     */
    public function sourceDocumentId(): ?int
    {
        return $this->note_type->isSales() ? $this->sales_invoice_id : $this->purchase_bill_id;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function subtotalAmount(): Money
    {
        return Money::of($this->subtotal);
    }

    public function discountAmount(): Money
    {
        return Money::of($this->discount_total);
    }

    public function taxAmount(): Money
    {
        return Money::of($this->tax_total);
    }

    public function grandTotalAmount(): Money
    {
        return Money::of($this->grand_total);
    }

    /**
     * Is this a credit note - does it reduce what the counterparty owes?
     *
     * The one method every consumer should ask rather than reading note_type, so
     * that "which way does this move the balance" has a single answer in the
     * codebase instead of one per call site.
     */
    public function isCredit(): bool
    {
        return $this->note_type->isCredit();
    }

    /**
     * Notes against one source document that have reached the ledger.
     *
     * Used by the adjustment service's aggregate and by the controller's index
     * filter. Expressed as a scope rather than a relation method with an argument
     * because both callers compose it into a larger query rather than fetching a
     * collection - the adjustment sum in particular must be a correlated
     * subquery, not a hydrated collection, or the concurrent-over-adjustment
     * guarantee would depend on which rows the database chose to send.
     *
     * @param  Builder<CreditDebitNote>  $query
     * @return Builder<CreditDebitNote>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Posted->value);
    }

    /**
     * @param  Builder<CreditDebitNote>  $query
     * @return Builder<CreditDebitNote>
     */
    public function scopeForInvoice(Builder $query, int $invoiceId): Builder
    {
        return $query->where('sales_invoice_id', $invoiceId);
    }

    /**
     * @param  Builder<CreditDebitNote>  $query
     * @return Builder<CreditDebitNote>
     */
    public function scopeForBill(Builder $query, int $billId): Builder
    {
        return $query->where('purchase_bill_id', $billId);
    }
}
