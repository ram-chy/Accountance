<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Money;
use App\Support\Rate;
use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_id',
    'payment_number',
    'payment_date',
    'amount',
    'payment_account_id',
    'reference',
    'notes',
    'currency_id',
    'exchange_rate',
])]
class SupplierPayment extends Model
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'status' => PaymentStatus::class,
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

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Currency and realised FX (Phase 14)
    |--------------------------------------------------------------------------
    |
    | This is where realised exchange gain and loss is born, because it is the one
    | place where two currencies meet:
    |
    |     carrying value = sum of allocations' base_amount  (the INVOICE's rate)
    |     cash received  = base_amount                    (this receipt's rate)
    |     realised FX    = cash received - carrying value
    |
    | Two snapshots from two different dates, which is the point: the invoice's rate
    | is what the receivable was recorded at, and this rate is what the money was
    | actually worth when it arrived. The difference happened; it is not an estimate.
    |
    | base_amount is null until posting, and null on pre-Phase-14 rows - for which
    | it equals amount, because a Phase 13 receipt was base currency at rate 1.
    | RealizedFxService treats null as "no conversion", which is what keeps every
    | existing settlement posting byte-identical.
    |
    | Allocations cannot carry their own currency: PaymentAllocationService refuses a
    | cross-currency allocation outright, so an allocation's currency is its parent's
    | and one column is enough.
    */

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function isForeignCurrency(): bool
    {
        return $this->currency_id !== null;
    }

    /**
     * The rate snapshot for the cash leg, or null for base currency.
     */
    public function exchangeRate(): ?Rate
    {
        return $this->exchange_rate === null ? null : Rate::of($this->exchange_rate);
    }

    /**
     * The amount received in the company's base currency.
     *
     * Prefers the value stored at posting. Falls back to converting the transaction
     * amount at the current rate snapshot so a draft can be previewed; never used on
     * the posting path.
     */
    public function baseAmount(): Money
    {
        if ($this->base_amount !== null) {
            return Money::of($this->base_amount);
        }

        $rate = $this->exchangeRate();

        return $rate === null
            ? $this->amountMoney()
            : $rate->applyTo($this->amountMoney());
    }
}
