<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Support\Money;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function scopeWithOutstandingBalance(Builder $query): Builder
    {
        $postedAllocations = DB::raw('(
            SELECT COALESCE(SUM(customer_receipt_allocations.amount), 0)
            FROM customer_receipt_allocations
            INNER JOIN customer_receipts
                ON customer_receipts.id = customer_receipt_allocations.customer_receipt_id
            WHERE customer_receipt_allocations.sales_invoice_id = sales_invoices.id
                AND customer_receipts.status = \''.PaymentStatus::Posted->value.'\'
        )');

        return $query
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->whereColumn('grand_total', '>', $postedAllocations);
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
