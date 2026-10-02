<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\PurchaseBillLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_bill_id',
    'line_number',
    'description',
    'quantity',
    'unit_cost',
    'discount',
    'tax_rate',
    'tax_amount',
    'line_total',
    'expense_account_id',
    /*
     * Phase 10. The configured tax this line was calculated with, if any.
     *
     * Fillable because PurchaseBillService writes it explicitly from the resolved
     * tax, never from the request body - the field is here for the same reason
     * tax_rate is. See the matching note on SalesInvoiceLine.
     */
    'tax_id',
])]
class PurchaseBillLine extends Model
{
    /** @use HasFactory<PurchaseBillLineFactory> */
    use HasFactory;

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class, 'purchase_bill_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function lineTotalAmount(): Money
    {
        return Money::of($this->line_total);
    }
}
