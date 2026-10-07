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
    'base_tax_amount',
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

    /*
    |--------------------------------------------------------------------------
    | Base currency tax (Phase 14)
    |--------------------------------------------------------------------------
    |
    | tax_amount is what the customer was charged, in the document's own currency.
    | base_tax_amount is what the ledger recorded, in the company's base currency.
    *
    | Both are stored rather than one being derived, because the tax report has to
    * total tax across documents in DIFFERENT currencies and the only correct single
    * total is the base-currency one. Recomputing it here from tax_amount and the
    * document rate would round at a second point from the one posting used, and the
    * report's total would then disagree with the journal by a fraction of a unit.
    *
    * null means "posted before base-Phase-14", which is a different statement from
    * "this line had no tax" and must not be conflated with a zero. The tax report
    * counts null-bearing lines separately and discloses them rather than silently
    * treating untaxed history as taxed at nil.
    *
    * A base-currency line's base_tax_amount equals tax_amount exactly.
    */
    public function taxAmount(): Money
    {
        return Money::of($this->tax_amount);
    }

    /**
     * This line's tax in the company's base currency.
     *
     * Prefers the stored value; falls back to tax_amount for base-currency lines and
     * for pre-Phase-14 rows, where the two are by definition the same figure.
     */
    public function baseTaxAmount(): Money
    {
        return $this->base_tax_amount === null
            ? $this->taxAmount()
            : Money::of($this->base_tax_amount);
    }

    /**
     * Does this line carry a converted base tax figure?
     *
     * False for a base-currency line and for pre-Phase-14 data. Only a report that
     * discloses coverage needs to tell these apart; arithmetic never does.
     */
    public function hasBaseTaxAmount(): bool
    {
        return $this->base_tax_amount !== null;
    }
}
