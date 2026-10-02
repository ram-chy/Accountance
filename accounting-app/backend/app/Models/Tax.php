<?php

namespace App\Models;

use App\Enums\TaxCalculationBasis;
use App\Enums\TaxType;
use App\Support\Money;
use Database\Factories\TaxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A tax this company charges or recovers.
 *
 * Deliberately carries no rate. The tax is the thing that exists across time -
 * "the sales tax this company applies" - and its rate is a series of effective
 * periods, which is what tax_rates holds. A rate column here would force a
 * choice between overwriting history (a document from March silently becomes 12%)
 * and freezing the first rate forever, and neither is acceptable in a ledger.
 *
 * Like Account, there is no company-scoped global scope: company isolation is
 * enforced in the service and controller layer against the active company, so a
 * deliberate cross-company report is still writable. Every query in this phase
 * filters explicitly.
 */
#[Fillable([
    'code',
    'name',
    'description',
    'tax_type',
    'calculation_basis',
])]
class Tax extends Model
{
    /** @use HasFactory<TaxFactory> */
    use HasFactory;

    /**
     * is_active, created_by and updated_by are absent on purpose.
     *
     * is_active is a lifecycle flag like accounts.is_active: it goes through
     * TaxService so the transition is validated and audited, and a new tax is
     * active by definition. created_by and updated_by come from the
     * authenticated user, never from a payload - an audit row recording whoever
     * the client said configured the tax is worthless.
     */
    protected function casts(): array
    {
        return [
            'tax_type' => TaxType::class,
            'calculation_basis' => TaxCalculationBasis::class,
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderBy('effective_from');
    }

    public function accountMapping(): HasOne
    {
        return $this->hasOne(TaxAccountMapping::class);
    }

    /**
     * @return HasMany<SalesInvoiceLine, $this>
     */
    public function salesInvoiceLines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    /**
     * @return HasMany<PurchaseBillLine, $this>
     */
    public function purchaseBillLines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class);
    }

    /**
     * Has any document line been calculated with this tax?
     *
     * The check behind the refuse-to-delete rule in TaxService. Two exists()
     * queries rather than one union: a union across two differently-parented tables
     * buys nothing here, because the answer is only ever yes or no and exists()
     * stops at the first row either way.
     */
    public function isReferencedByDocument(): bool
    {
        return $this->salesInvoiceLines()->exists()
            || $this->purchaseBillLines()->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * May this tax be charged on a sales document?
     */
    public function appliesToSales(): bool
    {
        return $this->tax_type->appliesToSales();
    }

    /**
     * May this tax be recovered on a purchase document?
     */
    public function appliesToPurchase(): bool
    {
        return $this->tax_type->appliesToPurchase();
    }

    /**
     * The rate in force on a date, or null if the tax has none.
     *
     * Defined once, here, because "which rate applies on date D" is the single
     * question the whole phase turns on and two implementations of it would
     * eventually disagree for a past date - which is precisely the failure that
     * must never happen to a figure already in the accounting record.
     *
     * Only active rates are considered. An inactive rate is one a user has
     * retired: it stays on file as history but must not be applied to a new
     * document.
     *
     * The bounds are inclusive at both ends, so a rate that ran 2026-01-01 to
     * 2026-03-31 and one that starts 2026-04-01 meet without a gap and without
     * a day belonging to both. The non-overlap that makes "one answer" true is
     * enforced by TaxRateService on write.
     *
     * @param  Collection<int, TaxRate>|null  $rates  pre-loaded to avoid a query per line
     */
    public function rateOn(Carbon|string $date, ?Collection $rates = null): ?TaxRate
    {
        $day = $date instanceof Carbon ? $date->toDateString() : $date;

        $candidates = $rates ?? $this->rates()->get();

        return $candidates
            ->filter(fn (TaxRate $rate) => $rate->isEffectiveOn($day))
            ->sortByDesc(fn (TaxRate $rate) => $rate->effective_from->toDateString())
            ->first();
    }

    /**
     * The rate in force today, or null.
     *
     * For display only. Nothing that writes a document may use this: a document's
     * rate must come from its own date, so a rate resolution that quietly used
     * "now" would be correct today and wrong for every backdated document.
     */
    public function currentRate(): ?TaxRate
    {
        return $this->rateOn(Carbon::today());
    }

    /**
     * Parse a submitted rate into the exact decimal the system stores.
     *
     * Lives here rather than in TaxRateService because the same conversion is
     * needed wherever a rate arrives - a form field, an API payload, a calculator
     * endpoint - and a percentage parsed two different ways is a rate that differs
     * in the last place depending on how it was submitted.
     *
     * ofTolerant, not of: a client sending 7.50000 for a column that stores four
     * decimals should be rounded the way the decimal tolerance config documents,
     * not rejected for precision the ledger will not keep anyway.
     */
    public static function rateAmountFor(mixed $value): Money
    {
        return Money::ofTolerant($value);
    }
}
