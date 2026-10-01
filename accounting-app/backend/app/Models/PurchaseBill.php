<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Support\Money;
use Database\Factories\PurchaseBillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        $postedAllocations = DB::raw('(
            SELECT COALESCE(SUM(supplier_payment_allocations.amount), 0)
            FROM supplier_payment_allocations
            INNER JOIN supplier_payments
                ON supplier_payments.id = supplier_payment_allocations.supplier_payment_id
            WHERE supplier_payment_allocations.purchase_bill_id = purchase_bills.id
                AND supplier_payments.status = \''.PaymentStatus::Posted->value.'\'
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
