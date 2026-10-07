<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\SupplierPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_payment_id',
    'purchase_bill_id',
    'amount',
    'base_amount',
])]
class SupplierPaymentAllocation extends Model
{
    /** @use HasFactory<SupplierPaymentAllocationFactory> */
    use HasFactory;

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }

    /*
    |--------------------------------------------------------------------------
    | Currency (Phase 14)
    |--------------------------------------------------------------------------
    |
    | base_amount is this allocation's share converted to the company's base
    | currency, at the SETTLED DOCUMENT's rate - not the invoice's.
    |
    | That distinction is the whole mechanism. The invoice's rate says what the
    * receivable was recorded at; the receipt's rate says what the cash was worth
    * when it arrived. RealizedFxService measures the difference between the two, so
    * this column must hold the carrying value being relieved, and reading the
    * invoice's rate here instead would collapse the difference to zero and quietly
    * eliminate every realised gain and loss in the system.
    *
    * Storing it rather than recomputing it means a receipt clearing three invoices
    * at three different rates attributes the FX difference per invoice, so the
    * customer statement and the ledger agree. A single receipt-level figure would
    * give the right total and the wrong per-invoice settlement.
    |
    | No currency_id: the allocation cannot differ from its parent receipt, which
    * PaymentAllocationService enforces.
    */

    /**
     * This allocation's amount in the company's base currency.
     *
     * Prefers the stored value; falls back to the transaction amount for pre-Phase-14
     * rows, where no conversion was performed.
     */
    public function baseAmount(): Money
    {
        return $this->base_amount === null
            ? $this->amountMoney()
            : Money::of($this->base_amount);
    }

    /**
     * Was this allocation recorded before base-Phase-14, or is it a base-currency
     * one? Both read the same and both mean the same; the distinction matters only
     * to a report that discloses coverage, never to arithmetic.
     */
    public function hasBaseAmount(): bool
    {
        return $this->base_amount !== null;
    }
}
