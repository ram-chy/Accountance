<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\CreditDebitNoteLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    /*
     * As with CreditDebitNote, no request-supplied monetary column is here on the
     * client's behalf: quantity, unit_price, discount, tax_rate, tax_amount,
     * line_total and tax_id are all written by CreditDebitNoteService from
     * DocumentCalculator's output, never from the request body. They are fillable
     * for exactly the reason they are fillable on SalesInvoiceLine - the service
     * persists a value it computed itself, through the hasMany relation.
     *
     * account_id IS fillable for the same reason revenue_account_id is on
     * SalesInvoiceLine: the service persists a value it has already validated
     * through TransactionAccountResolver. No request rule accepts the field
     * unvalidated - it is company-scoped by exists() - and the resolver decides
     * whether a REVENUE or an EXPENSE account is appropriate.
     *
     * The four below are the ones worth arguing about, because they are all
     * server-owned and still fillable:
     *
     *  - credit_debit_note_id is set by the hasMany relation, and is fillable on
     *    SalesInvoiceLine for the same reason.
     *  - line_number is assigned by DocumentCalculator in document order. An
     *    earlier draft of this attribute list left it out on the reasoning that
     *    "the calculator decides it, so it must not be mass-assignable" - which
     *    silently dropped the column on insert and let the database refuse the
     *    write. Fillable means "this service may persist this", not "a client may
     *    choose this"; nothing in the note path ever passes a line number the
     *    client supplied.
     *  - the two source-line references are resolved and validated against the
     *    note's OWN source document by CreditDebitNoteAdjustmentService before
     *    being written, which is the entire reason a note line cannot consume
     *    another document's quantity. Making them fillable does not weaken that:
     *    no request field reaches them unvalidated, and the check happens before
     *    this insert rather than after it.
     */
    'credit_debit_note_id',
    'line_number',
    'sales_invoice_line_id',
    'purchase_bill_line_id',
    'description',
    'quantity',
    'unit_price',
    'discount',
    'tax_rate',
    'tax_amount',
    'line_total',
    'tax_id',
    'account_id',
])]
class CreditDebitNoteLine extends Model
{
    /** @use HasFactory<CreditDebitNoteLineFactory> */
    use HasFactory;

    public function note(): BelongsTo
    {
        return $this->belongsTo(CreditDebitNote::class, 'credit_debit_note_id');
    }

    /**
     * The invoice line this note line adjusts, for a line-level sales note.
     *
     * Null both for a purchase note and for a document-level sales note, which is
     * why this and purchaseBillLine are optional everywhere they are read.
     */
    public function salesInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class);
    }

    /**
     * The bill line this note line adjusts, for a line-level purchase note.
     */
    public function purchaseBillLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseBillLine::class);
    }

    /**
     * The source line this note line adjusts, as one relation.
     *
     * Null when the adjustment is document-level. Same convenience and same
     * limitation as CreditDebitNote::sourceDocument(): it resolves whichever kind
     * is plausible, so a caller that has not established the note is a sales note
     * should not use it.
     *
     * @return BelongsTo<SalesInvoiceLine|PurchaseBillLine, $this>
     */
    public function sourceLine(): BelongsTo
    {
        return $this->sales_invoice_line_id !== null
            ? $this->salesInvoiceLine()
            : $this->purchaseBillLine();
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    /**
     * The revenue account for a sales note line, the expense account for a
     * purchase note line.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function quantityAmount(): Money
    {
        return Money::of($this->quantity);
    }

    public function unitPriceAmount(): Money
    {
        return Money::of($this->unit_price);
    }

    public function lineTotalAmount(): Money
    {
        return Money::of($this->line_total);
    }

    /**
     * The source line's key, whichever kind it is, or null for a document-level
     * adjustment.
     */
    public function sourceLineId(): ?int
    {
        return $this->sales_invoice_line_id ?? $this->purchase_bill_line_id;
    }
}
