<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Money;
use Database\Factories\CustomerReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id',
    'receipt_number',
    'receipt_date',
    'amount',
    'payment_account_id',
    'reference',
    'notes',
])]
class CustomerReceipt extends Model
{
    /** @use HasFactory<CustomerReceiptFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'status' => PaymentStatus::class,
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
        return $this->hasMany(CustomerReceiptAllocation::class);
    }

    public function amountMoney(): Money
    {
        return Money::of($this->amount);
    }
}
