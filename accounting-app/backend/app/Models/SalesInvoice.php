<?php

namespace App\Models;

use App\Enums\NoteType;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Support\Money;
use App\Support\Rate;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'invoice_number',
    'invoice_date',
    'due_date',
    'subtotal',
    'discount_total',
    'tax_total',
    'grand_total',
    'notes',
    'tax_account_id',
    'currency_id',
    'exchange_rate',
])]
class SalesInvoice extends Model
{
    /** @use HasFactory<SalesInvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'status' => TransactionStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Currency (Phase 14)
    |--------------------------------------------------------------------------
    |
    | subtotal / tax_total / grand_total are amounts in the invoice's own currency,
    | which is null-currency_id (the company's base) unless a foreign currency was
    | chosen. They were never renamed or duplicated - see the documents migration
    | for why base_grand_total exists but base_subtotal does not.
    |
    | base_grand_total and base_tax_total are written ONCE, at posting, and never
    | recomputed. base_tax_total in particular is not derivable from the tax report
    | later without re-deriving a conversion that this row already performed.
    |
    | The rate is a snapshot, not a lookup. Re-reading the rate table would let a
    | rate correction silently revalue a posted invoice, which is the single most
    | damaging thing a multi-currency system can do to an accounting record.
    */

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Was this invoice raised in a currency other than the company's base?
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_id !== null;
    }

    /**
     * The rate snapshot: base currency per one unit of the invoice currency.
     *
     * Returns null for a base-currency invoice, and Rate::one() rather than null
     * when a caller explicitly wants the identity - baseAmount() below is the usual
     * way to ask.
     */
    public function exchangeRate(): ?Rate
    {
        return $this->exchange_rate === null ? null : Rate::of($this->exchange_rate);
    }

    /**
     * The invoice's grand total in the company's base currency.
     *
     * Before posting this falls back to converting grand_total at the current rate
     * snapshot, so a draft can be displayed in base terms - a read-only preview,
     * clearly distinguishable from the stored figure because that one is null until
     * the invoice is posted. After posting the stored value wins, unconditionally.
     *
     * The fallback is deliberately NOT used for anything that writes to the ledger.
     */
    public function baseGrandTotal(): Money
    {
        if ($this->base_grand_total !== null) {
            return Money::of($this->base_grand_total);
        }

        $rate = $this->exchangeRate();

        return $rate === null
            ? $this->grandTotalAmount()
            : $rate->applyTo($this->grandTotalAmount());
    }

    public function baseTaxTotal(): Money
    {
        if ($this->base_tax_total !== null) {
            return Money::of($this->base_tax_total);
        }

        $rate = $this->exchangeRate();

        return $rate === null
            ? $this->taxAmount()
            : $rate->applyTo($this->taxAmount());
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('line_number');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
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
     * Invoices that still owe money.
     *
     * The comparison is a correlated subquery rather than a join-and-subtract,
     * because there is no paid_total column to compare against - the figure only
     * exists as a sum over posted receipts. MySQL evaluates DECIMAL subtraction
     * exactly, so the comparison is the same answer Money would give; it is not
     * the float comparison that would be unsafe.
     *
     * The cost is one subquery per candidate row, which is why the scope is
     * opt-in for a filter rather than applied to every index query. A stored
     * column would make this cheap, and storing one is the trade this phase
     * declined: a cached total that can silently disagree with the allocations
     * it summarises has no correct resolution, and no way to notice.
     *
     * Drafts are excluded. A draft has no journal, so its balance is not money
     * anyone owes, and an outstanding-AR report that included drafts would
     * disagree with the ledger by exactly the draft total.
     *
     * PHASE 11: POSTED NOTES ENTER THE COMPARISON
     *
     * The balance is no longer `grand_total > paid`. A posted credit note reduces
     * what the customer owes and a posted debit note increases it, so both take
     * part with their own sign:
     *
     *     outstanding = grand_total - paid - (credits - debits)
     *
     * Only POSTED notes are summed, for the same reason only POSTED receipts are:
     * a draft note has no journal and has adjusted nothing.
     *
     * The note sum is SUBTRACTED rather than added to the right-hand side, because
     * the CASE inside it already carries the sign - a credit note's grand_total
     * arrives there as a negative number, so subtracting the sum credits the
     * invoice. Writing `grand_total > paid + notes` would be a subtly different
     * and wrong expression of the same rule, and the sign is exactly the kind of
     * thing that is easy to get backwards and hard to notice: a wrongly signed
     * note sum does not produce a 500, it produces plausible numbers.
     *
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function scopeWithOutstandingBalance(Builder $query): Builder
    {
        /*
         * Both subqueries are plain SQL strings rather than DB::raw() fragments,
         * because two of them have to be combined into one comparison and PHP cannot
         * concatenate an Expression into a string. The two status values are passed
         * as bindings rather than interpolated, so no part of this is a value the
         * database has to be trusted to parse as a literal.
         */
        $postedAllocations = '(
            SELECT COALESCE(SUM(customer_receipt_allocations.amount), 0)
            FROM customer_receipt_allocations
            INNER JOIN customer_receipts
                ON customer_receipts.id = customer_receipt_allocations.customer_receipt_id
            WHERE customer_receipt_allocations.sales_invoice_id = sales_invoices.id
                AND customer_receipts.status = ?
        )';

        $postedNotes = '(
            SELECT COALESCE(SUM(
                CASE WHEN credit_debit_notes.note_type IN (?, ?)
                    THEN credit_debit_notes.grand_total
                    ELSE -credit_debit_notes.grand_total
                END
            ), 0)
            FROM credit_debit_notes
            WHERE credit_debit_notes.sales_invoice_id = sales_invoices.id
                AND credit_debit_notes.status = ?
        )';

        return $query
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->whereRaw(
                'grand_total - '.$postedAllocations.' - '.$postedNotes.' > 0',
                [
                    PaymentStatus::Posted->value,
                    NoteType::SalesCreditNote->value,
                    NoteType::PurchaseCreditNote->value,
                    TransactionStatus::Posted->value,
                ]
            );
    }

    /*
    | ---------------------------------------------------------------------------
    | Settlement figures
    | ---------------------------------------------------------------------------
    |
    | paid_total and balance_due are not columns. They are sums over posted
    | allocations, computed by SettlementService, and attached to the model by
    | the controller for the duration of one request.
    |
    | A plain PHP property rather than a relationship accessor, because the sum
    | is per-document and would be re-issued on every read: an index response
    | eager-loading 100 invoices would otherwise run 100 aggregate queries to
    | serialise a list. The controller computes them once, in bulk, via
    | SettlementService::figuresForMany().
    |
    | Deliberately NOT $this->attributes['settlement_figures']. Eloquent cannot
    | tell a non-column attribute from a column it does not know about, so it
    | would carry the array through fill() into the UPDATE statement when the model
    | is saved - writing to a column that does not exist. A typed property has no
    | such ambiguity.
    |
    | The accessors below return zero when nothing was attached, so a caller that
    | forgets to attach gets a safe value rather than a null dereference. That
    | fallback is a convenience, not a licence to skip it: the resource omits the
    | keys entirely when hasSettlement() is false, so a client can tell "nothing
    | allocated" from "not calculated".
    */

    /**
     * @var array{paid_total: string, balance_due: string}|null
     */
    private ?array $settlement = null;

    /**
     * @param  array{paid_total: string, balance_due: string}  $figures
     */
    public function setSettlement(array $figures): static
    {
        $this->settlement = $figures;

        return $this;
    }

    public function hasSettlement(): bool
    {
        return $this->settlement !== null;
    }

    public function paidTotalAmount(): Money
    {
        return Money::of($this->settlement['paid_total'] ?? '0');
    }

    public function balanceDueAmount(): Money
    {
        return Money::of($this->settlement['balance_due'] ?? '0');
    }

    /**
     * An invoice is overdue when money is still owed and the due date has passed.
     *
     * Both halves are required. A settled invoice is never overdue however old it
     * is, and an invoice with no due date yet is not overdue because there is no
     * date to be past.
     */
    public function isOverdue(): bool
    {
        if ($this->due_date === null) {
            return false;
        }

        return $this->balanceDueAmount()->isPositive()
            && $this->due_date->isPast();
    }

    /**
     * Whole days past the due date, or 0 when not overdue.
     */
    public function daysOverdue(): int
    {
        if (! $this->isOverdue() || $this->due_date === null) {
            return 0;
        }

        return (int) $this->due_date->startOfDay()->diffInDays(now()->startOfDay());
    }
}
