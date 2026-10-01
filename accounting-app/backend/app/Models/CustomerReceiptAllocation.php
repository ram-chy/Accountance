<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\CustomerReceiptAllocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_receipt_id',
    'sales_invoice_id',
    'amount',
])]
class CustomerReceiptAllocation extends Model
{
    /** @use HasFactory<CustomerReceiptAllocationFactory> */
    use HasFactory;

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }
}
