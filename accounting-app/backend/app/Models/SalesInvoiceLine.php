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
    /*
     * Phase 10. The configured tax this line was calculated with, if any.
     *
     * Fillable because SalesInvoiceService writes it explicitly from the resolved
     * tax, never from the request body: the value is a service decision, and the
     * request's own tax input is a rate that the calculator turns into this. A
     * client cannot name a tax by sending tax_id, because no request rule and no
     * controller path passes one through - the field is in the model's fillable
     * list for the same reason tax_rate is, which is that the service persists it.
     *
     * Nullable, and null is the ordinary case for every document written before
     * Phase 10 and for any line still using a hand-entered rate.
     */
    'tax_id',
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
