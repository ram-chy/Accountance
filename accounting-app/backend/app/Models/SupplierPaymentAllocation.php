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
}
