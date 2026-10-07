<?php

namespace App\Models;

use App\Enums\NoteType;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Support\Money;
use App\Support\Rate;
use Database\Factories\PurchaseBillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_id',
    'bill_number',
    'bill_date',
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
class PurchaseBill extends Model
{
    /** @use HasFactory<PurchaseBillFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'status' => TransactionStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Currency (Phase 14)
    |--------------------------------------------------------------------------
    |
    |  The transaction currency is null - meaning the company's base -
    | unless a foreign currency was chosen. The existing amount columns were neither
    | renamed nor duplicated; base_grand_total and base_tax_total are additional
    | columns written once at posting, and the rate is a snapshot rather than a
    | lookup so a later rate correction cannot revalue a posted document.
    */

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * Was this document raised in a currency other than the company's base?
     */
    public function isForeignCurrency(): bool
    {
        return $this->currency_id !== null;
    }

    /**
     * The rate snapshot, or null for a base-currency document.
     */
    public function exchangeRate(): ?Rate
    {
        return $this->exchange_rate === null ? null : Rate::of($this->exchange_rate);
    }

    /**
     * The grand total in the company's base currency.
     *
     * Prefers the value stored at posting; falls back to converting the transaction
     * total at the current rate snapshot so a draft can be previewed in base terms.
     * Never used on the posting path, where the converted figure is written rather
     * than re-derived.
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
        return $this->hasMany(PurchaseBillLine::class)->orderBy('line_number');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function grandTotalAmount(): Money
    {
        return Money::of($this->grand_total);
    }

    /**
     * Bills still owed money.
     *
     * The mirror of SalesInvoice::scopeWithOutstandingBalance(), including the
     * reasons: no paid_total column exists, MySQL's DECIMAL comparison is exact,
     * the subquery is a deliberate cost accepted for an opt-in filter, and drafts
     * are excluded because a draft has no journal behind its balance.
     *
     * @param  Builder<PurchaseBill>  $query
     * @return Builder<PurchaseBill>
     */
    public function scopeWithOutstandingBalance(Builder $query): Builder
    {
        $postedAllocations = '(
            SELECT COALESCE(SUM(supplier_payment_allocations.amount), 0)
            FROM supplier_payment_allocations
            INNER JOIN supplier_payments
                ON supplier_payments.id = supplier_payment_allocations.supplier_payment_id
            WHERE supplier_payment_allocations.purchase_bill_id = purchase_bills.id
                AND supplier_payments.status = ?
        )';

        $postedNotes = '(
            SELECT COALESCE(SUM(
                CASE WHEN credit_debit_notes.note_type IN (?, ?)
                    THEN credit_debit_notes.grand_total
                    ELSE -credit_debit_notes.grand_total
                END
            ), 0)
            FROM credit_debit_notes
            WHERE credit_debit_notes.purchase_bill_id = purchase_bills.id
                AND credit_debit_notes.status = ?
        )';

        /*
         * PHASE 11. Identical to SalesInvoice::scopeWithOutstandingBalance, with
         * the bill's own paid subquery - see that scope for why the note sum is
         * subtracted rather than added, and why the sign lives in the CASE.
         */
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
    | The mirror of the same block on SalesInvoice: paid_total and balance_due are
    | sums over posted allocations, not columns, attached per request by
    | SettlementService::attachFiguresForBills() so a list endpoint costs one
    | aggregate rather than one per bill.
    |
    | A typed private property rather than $this->attributes[...], which Eloquent
    | would treat as a real column and try to write on every save.
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
     * Both halves required: a settled bill is never overdue however old it is, and
     * a bill with no due date is not overdue because there is no date to be past.
     */
    public function isOverdue(): bool
    {
        if ($this->due_date === null) {
            return false;
        }

        return $this->balanceDueAmount()->isPositive()
            && $this->due_date->isPast();
    }

    public function daysOverdue(): int
    {
        if (! $this->isOverdue() || $this->due_date === null) {
            return 0;
        }

        return (int) $this->due_date->startOfDay()->diffInDays(now()->startOfDay());
    }
}
