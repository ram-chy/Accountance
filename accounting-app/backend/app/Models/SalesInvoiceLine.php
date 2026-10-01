<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\SalesInvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sales_invoice_id',
    'line_number',
    'description',
    'quantity',
    'unit_price',
    'discount',
    'tax_rate',
    'tax_amount',
    'line_total',
    'revenue_account_id',
])]
class SalesInvoiceLine extends Model
{
    /** @use HasFactory<SalesInvoiceLineFactory> */
    use HasFactory;

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
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
}
