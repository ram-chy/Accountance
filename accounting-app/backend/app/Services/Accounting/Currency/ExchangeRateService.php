<?php

namespace App\Services\Accounting\Currency;

use App\Enums\AuditAction;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CustomerReceipt;
use App\Models\ExchangeRate;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Money;
use App\Support\Rate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Exchange rates: the single authority on what a currency is worth to a company.
 *
 * THE ONE RULE THIS CLASS EXISTS TO KEEP
 *
 * A rate is dated history, never a mutable current value. Everything else in this
 * phase follows from that: a document snapshots the rate it was priced at, a
 * posted journal is never re-converted, and changing today's rate is a matter of
 * inserting a later row rather than editing an earlier one. A system where the
 * current rate overwrote the old one would report two different base amounts for
 * the same posted document on two different days, with the difference
 * indistinguishable from a fraud.
 *
 * THE DIRECTION CONVENTION, WHICH IS NOT A DETAIL
 *
 * `rate` is units of `to_currency` per ONE unit of `from_currency`, and conversion
 * is always a multiply. No rate is stored the other way round and no conversion
 * path divides. A rate table whose rows disagree with the form that created them
 * is worse than no rate table, and normalising pairs into some canonical base pair
 * would have done exactly that while looking tidier.
 *
 * WHY THERE IS NO AUTOMATIC INVERSION
 *
 * A tempting convenience: given USD/INR, synthesise INR/USD so a user can convert
 * either way with one row. Rejected because the reciprocal is a derived figure, and
 * a derived figure that behaves identically to a stored one cannot be told apart
 * from a stored one when they disagree. The day the synthesised reciprocal and a
 * genuinely quoted reverse rate differ by a fraction - and they will, because real
 * markets have spreads - there is no way to tell which one a document was priced
 * with. Rate::reciprocal() exists for the explicit, single-step case.
 *
 * THE OVERLAP PROBLEM, AND WHAT IS ACTUALLY ENFORCED
 *
 * "No two rates for a pair may have overlapping validity" cannot be expressed as a
 * MySQL unique index, which is why TaxRateService solves the same shape of problem
 * in the service layer. Here the enforceable rule is stricter and simpler: one row
 * per (company, pair, day), enforced by a unique index. Resolution is "the newest
 * row at or before the date being priced", which is the same latest-wins rule
 * Tax::rateOn() uses - and a caller that wants to change a rate from a given day
 * forward inserts a later row.
 *
 * The consequence worth stating: a rate stays in force until a later one replaces
 * it, so there is no need to close periods and no window in which a date has no
 * rate. The cost is that a rate table is longer than it strictly needs to be.
 */
class ExchangeRateService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Company $company, array $data, ?User $actor = null): ExchangeRate
    {
        $from = $this->resolveActiveCurrency($data['from_currency_id'], 'from_currency_id');
        $to = $this->resolveActiveCurrency($data['to_currency_id'], 'to_currency_id');

        $rate = $this->parseRate($data['rate']);
        $effectiveDate = $this->day($data['effective_date']);

        if ($from->getKey() === $to->getKey()) {
            /*
             * A self-pair is legal - it is exactly 1, and it is how a company says
             * "this currency is also my functional currency" - but only at rate 1.
             * A self-pair quoting 83.5 is not a rate anyone wants, and the resolver
             * must never have to think about it.
             */
            if (! $rate->isOne()) {
                throw ValidationException::withMessages([
                    'rate' => 'A currency cannot have an exchange rate with itself other than 1.',
                ]);
            }
        }

        return DB::transaction(function () use ($company, $from, $to, $rate, $effectiveDate, $data, $actor): ExchangeRate {
            $new = new ExchangeRate;
            $new->company_id = $company->getKey();
            $new->fill([
                'from_currency_id' => $from->getKey(),
                'to_currency_id' => $to->getKey(),
                'effective_date' => $effectiveDate,
                'rate' => $rate->toDatabase(),
                'source' => isset($data['source']) && $data['source'] !== ''
                    ? mb_substr((string) $data['source'], 0, 100)
                    : null,
            ]);
            $new->forceFill(['is_active' => true, 'created_by' => $actor?->getKey() ?? auth()->id()]);

            try {
                $new->save();
            } catch (QueryException $e) {
                /*
                 * The one-row-per-pair-per-day rule is the database's to hold, because
                 * two users saving "USD to INR on the 3rd" at the same moment both
                 * pass any application-level uniqueness check and both insert - after
                 * which "the rate on the 3rd" has two answers and the resolver has to
                 * break a tie it should never face.
                 *
                 * Translated into a message rather than allowed to surface as a 500,
                 * because the cause is a business rule and the caller can act on it.
                 */
                if ($this->isUniqueViolation($e)) {
                    throw ValidationException::withMessages([
                        'effective_date' => sprintf(
                            'A rate for %s on %s already exists. Record the change as a new effective date instead of editing history.',
                            $new->pair(),
                            $effectiveDate,
                        ),
                    ]);
                }

                throw $e;
            }

            $this->audit->created($new, $actor);

            return $new;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(ExchangeRate $exchangeRate, array $data, ?User $actor = null): ExchangeRate
    {
        /*
         * Refusing to edit a rate that has already priced a document, rather than
         * merely warning about it.
         *
         * The document's own exchange_rate snapshot is what its journal lines and its
         * base_grand_total were computed from. Editing the rate row afterwards would
         * not change those - they are stored - so the rate table would claim one
         * rate while the documents it priced say another. Anyone reconstructing the
         * figure from the rate table would get a number that disagrees with the
         * ledger, and there would be no way to tell which was wrong.
         *
         * So the rule is: correct it by inserting a new effective date. That keeps
         * the table honest, and it is the same rule as "a rate is history".
         */
        if ($this->hasPricedDocuments($exchangeRate)) {
            throw ValidationException::withMessages([
                'rate' => 'This rate has already been used to price a document and cannot be changed. '
                    .'Record the correction as a new effective date instead.',
            ]);
        }

        if (array_key_exists('rate', $data)) {
            $exchangeRate->rate = $this->parseRate($data['rate'])->toDatabase();
        }

        if (array_key_exists('effective_date', $data)) {
            $exchangeRate->effective_date = $this->day($data['effective_date']);
        }

        if (array_key_exists('source', $data)) {
            $exchangeRate->source = $data['source'] === null || $data['source'] === ''
                ? null
                : mb_substr((string) $data['source'], 0, 100);
        }

        DB::transaction(function () use ($exchangeRate, $actor): void {
            $exchangeRate->save();

            $this->audit->updated($exchangeRate, $actor);
        });

        return $exchangeRate->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function deactivate(ExchangeRate $exchangeRate, ?User $actor = null): ExchangeRate
    {
        if (! $exchangeRate->is_active) {
            throw ValidationException::withMessages([
                'rate' => 'This rate is already inactive.',
            ]);
        }

        $before = ['rate' => $exchangeRate->rate, 'is_active' => true];

        DB::transaction(function () use ($exchangeRate, $before, $actor): void {
            $exchangeRate->forceFill([
                'is_active' => false,
                'updated_by' => $actor?->getKey() ?? auth()->id(),
            ])->save();

            $this->audit->lifecycle(
                AuditAction::Deactivated,
                $exchangeRate,
                $actor,
                $before,
                ['rate' => $exchangeRate->rate, 'is_active' => false],
            );
        });

        return $exchangeRate->refresh();
    }

    /**
     * @throws ValidationException
     */
    public function activate(ExchangeRate $exchangeRate, ?User $actor = null): ExchangeRate
    {
        if ($exchangeRate->is_active) {
            throw ValidationException::withMessages([
                'rate' => 'This rate is already active.',
            ]);
        }

        $before = ['rate' => $exchangeRate->rate, 'is_active' => false];

        DB::transaction(function () use ($exchangeRate, $before, $actor): void {
            $exchangeRate->forceFill([
                'is_active' => true,
                'updated_by' => $actor?->getKey() ?? auth()->id(),
            ])->save();

            $this->audit->lifecycle(
                AuditAction::Activated,
                $exchangeRate,
                $actor,
                $before,
                ['rate' => $exchangeRate->rate, 'is_active' => true],
            );
        });

        return $exchangeRate->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution
    |--------------------------------------------------------------------------
    |
    | Everything above is configuration. Everything below is the question the rest
    | of the application asks, and there is exactly one implementation of it here -
    | the phase brief's section 34, which asks for a single resolver rather than
    | rate lookup scattered through the posting services.
    */

    /**
     * The rate in force for a company converting $from into its base currency.
     *
     * $date is the DOCUMENT's date, never today. A rate resolution that quietly used
     * the current date would be correct for a document entered today and wrong for
     * every backdated one, which is the failure mode PHASE 10's rateOn() documents
     * and the reason currentRate() exists separately for display only.
     *
     * @return array{rate: ExchangeRate, amount: Money}|null null when no rate exists
     */
    public function resolveFor(
        Company $company,
        Currency $from,
        Carbon|string $date,
        ?Money $amount = null,
    ): ?array {
        $base = $company->currency;

        if ($base === null) {
            /*
             * No base currency configured, so "convert into the base currency" has no
             * meaning. Refusing to guess is the whole point of the no-seeder
             * decision: a default would silently misstate every foreign amount.
             */
            throw ValidationException::withMessages([
                'currency_id' => 'This company has no base currency configured. Set one before using a foreign currency.',
            ]);
        }

        $resolved = $this->findRate($company, $from, $base, $date);

        if ($resolved === null) {
            return null;
        }

        if ($amount === null) {
            return ['rate' => $resolved, 'amount' => Money::zero()];
        }

        return ['rate' => $resolved, 'amount' => $resolved->convert($amount)];
    }

    /**
     * The rate in force for an explicit pair, or null.
     */
    public function findRate(Company $company, Currency $from, Currency $to, Carbon|string $date): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('company_id', $company->getKey())
            ->forPair((int) $from->getKey(), (int) $to->getKey())
            ->active()
            ->onOrBefore($date)
            ->first();
    }

    /**
     * The rate in force for a pair, or a validation error naming the missing pair.
     *
     * This is the form every posting path uses, because "no rate exists" has to
     * arrive as a message naming the currency pair and the date - not as an
     * exception from deep inside an arithmetic helper after the user has filled in a
     * whole document.
     *
     * @throws ValidationException
     */
    public function rateOrFail(
        Company $company,
        Currency $from,
        Currency $to,
        Carbon|string $date,
        string $field = 'currency_id',
    ): ExchangeRate {
        $rate = $this->findRate($company, $from, $to, $date);

        if ($rate !== null) {
            return $rate;
        }

        throw ValidationException::withMessages([
            $field => sprintf(
                'No exchange rate is configured from %s to %s on or before %s.',
                $from->code,
                $to->code,
                $date instanceof Carbon ? $date->toDateString() : $date,
            ),
        ]);
    }

    /**
     * All active rates a company has for a set of currencies, on one date.
     *
     * Batched on purpose. A document with fifty lines posted through a service that
     * resolved each line separately would issue fifty queries against the same table
     * for what is one answer - and the answers could even differ, since a rate row
     * could be inserted between them.
     *
     * Returns Rate objects, not ExchangeRate rows, and that is a deliberate
     * narrowing. The caller of a batch resolver needs the number to multiply by, and
     * handing it a row would mean every one of them calling ->rate() anyway. The
     * single-rate path (rateOrFail) keeps returning the row, because the error
     * message it builds needs the pair and the effective date.
     *
     * Returns `array<string, Rate>` keyed by the FOREIGN currency's code. A currency
     * with no rate is simply absent from the map - the caller decides whether one
     * missing rate among fifty lines is fatal, and a resolver that threw would
     * remove that choice.
     *
     * @param  Collection<int, Currency>  $currencies
     * @return array<string, Rate>
     */
    public function resolveMany(
        Company $company,
        Collection $currencies,
        Carbon|string $date,
    ): array {
        $base = $company->currency;

        if ($base === null) {
            throw ValidationException::withMessages([
                'currency_id' => 'This company has no base currency configured. Set one before using a foreign currency.',
            ]);
        }

        $ids = array_values(array_unique(array_map(
            fn (Currency $c) => (int) $c->getKey(),
            $currencies->all()
        )));

        if ($ids === []) {
            return [];
        }

        /*
         * The base currency is included in the query rather than special-cased, so
         * the returned map is complete for every currency the caller asked about -
         * including the base, which resolves to a rate of 1.
         */
        $rates = ExchangeRate::query()
            ->where('company_id', $company->getKey())
            ->whereIn('from_currency_id', array_merge($ids, [(int) $base->getKey()]))
            ->active()
            ->onOrBefore($date)
            ->get();

        /*
         * Latest wins per pair. orderByDesc is already applied by the scope, so the
         * first row seen for a pair is the newest - and `??=` keeps it while ignoring
         * the older ones, which is what "the rate in force on date D" means.
         */
        $newestByPair = [];

        foreach ($rates as $rate) {
            $newestByPair[$rate->from_currency_id.':'.$rate->to_currency_id] ??= $rate;
        }

        $resolved = [];

        foreach ($currencies as $currency) {
            $key = $currency->getKey().':'.$base->getKey();

            if (isset($newestByPair[$key])) {
                $resolved[$currency->code] = $newestByPair[$key]->rate();

                continue;
            }

            /*
             * No row at all for the base currency against itself. That is the normal
             * state, not a gap: a company that books in its own currency has no need
             * to record a rate to itself. The identity is manufactured here, in one
             * place, so that every caller can multiply by whatever it is handed
             * without testing for null first.
             */
            if ((int) $currency->getKey() === (int) $base->getKey()) {
                $resolved[$currency->code] = Rate::one();
            }
        }

        return $resolved;
    }

    /**
     * Has this rate already priced a document?
     *
     * Matched on currency_id AND the exact rate string, not on "a document exists
     * for this currency". The question is narrower than it looks: a rate can be
     * edited safely while its pair is in use, as long as the rows that priced
     * documents snapshotted a DIFFERENT rate - which is exactly the case after a
     * correction is recorded on a new effective date. Refusing every edit to a
     * currency anyone has ever transacted in would make the documented correction
     * workflow impossible.
     *
     * The rate is compared as a decimal string. exchange_rate columns are
     * DECIMAL(20,10) and MySQL compares them numerically, so '83.5000000000'
     * matches a stored 83.5 - which is the right answer, because the stored figure
     * IS the rate, not a different rate that happens to look similar.
     *
     * Existence checks rather than counts, short-circuited, because the answer is
     * only ever yes or no.
     */
    private function hasPricedDocuments(ExchangeRate $exchangeRate): bool
    {
        $matching = fn ($query) => $query
            ->where('currency_id', $exchangeRate->from_currency_id)
            ->where('exchange_rate', $exchangeRate->rate);

        $scoped = fn (string $model) => $model::query()
            ->where('company_id', $exchangeRate->company_id);

        return $matching($scoped(SalesInvoice::class))->exists()
            || $matching($scoped(PurchaseBill::class))->exists()
            || $matching($scoped(CustomerReceipt::class))->exists()
            || $matching($scoped(SupplierPayment::class))->exists()
            || $matching($scoped(CashBankTransaction::class))->exists();
    }

    /**
     * Turn user input into a Rate, or into a field-level validation error.
     *
     * Rate::of() throws InvalidArgumentException, which is the right thing for a
     * value object to do and the wrong thing for a request: uncaught, a rate of "-5"
     * or "0" or one with eleven decimal places returns HTTP 500 and a stack trace,
     * when the user has done nothing more remarkable than mistype a number and
     * deserves a message beside the field.
     *
     * So the rejection is re-raised as a ValidationException naming the field, and
     * the value object's own wording is kept rather than replaced - it is more
     * specific than anything this layer could invent, and the user is as entitled to
     * being told the scale is ten places as to being told the rate was invalid.
     *
     * This is the same translation JournalService and the transaction request
     * classes perform on Money, and it is done here rather than in a FormRequest
     * because this service is called from several places and only one of them is a
     * request.
     *
     * @throws ValidationException
     */
    private function parseRate(mixed $value): Rate
    {
        try {
            return Rate::of($value);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'rate' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolveActiveCurrency(mixed $id, string $field): Currency
    {
        $currency = Currency::query()->find($id);

        if ($currency === null) {
            throw ValidationException::withMessages([
                $field => 'The selected currency does not exist.',
            ]);
        }

        if (! $currency->is_active) {
            throw ValidationException::withMessages([
                $field => "Currency [{$currency->code}] exists but is inactive.",
            ]);
        }

        return $currency;
    }

    private function day(Carbon|string $date): Carbon
    {
        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }
}
