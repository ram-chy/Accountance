<?php

namespace App\Services\Accounting\Currency;

use App\Models\Account;
use App\Models\Company;
use App\Models\Currency;
use App\Models\JournalLine;
use App\Support\Money;
use App\Support\Rate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Decides what currency a document is in, and whether its accounts may be used.
 *
 * THE ONE SERVICE EVERY DOCUMENT POSTING PATH GOES THROUGH
 *
 * Six posting services need the same two answers - which currency, at what rate -
 * and would each otherwise reach for ExchangeRateService directly. Six call sites
 * means six chances to pass the wrong date, and the wrong date is the most
 * dangerous mistake available in this phase: resolving "today's" rate for a
 * backdated invoice is wrong for that invoice while looking entirely reasonable.
 *
 * So the date is a required argument here, named $documentDate, and the service
 * refuses to resolve without it. There is no overload that defaults to today,
 * because a default that is right 99% of the time is exactly the kind of default
 * that silently misstates backdated documents.
 *
 * WHY ACCOUNT VALIDATION LIVES HERE RATHER THAN IN THE POSTING SERVICES
 *
 * Because "may this account hold this currency" is one rule about one column, and
 * each posting service answering it separately would be six copies that drift.
 * Account::acceptsCurrency() owns the rule itself; this service owns the one thing
 * that must also happen - reading the account's own currency, and translating a
 * refusal into a message that names the account and the currency rather than
 * surfacing a foreign key error.
 */
class DocumentCurrencyService
{
    public function __construct(
        private readonly ExchangeRateService $rates,
    ) {}

    /**
     * Resolve the currency context for a document being saved.
     *
     * A null $currencyId means "the company's base currency", which is the Phase 13
     * shape and the default for every existing document. It does NOT require the
     * company to have a base currency configured: a single-currency company books in
     * its own amounts at an implicit rate of 1, and refusing that would break every
     * company that has not yet chosen a base currency for reasons that have nothing
     * to do with this document.
     *
     * @param  int|string|null  $currencyId  null for base currency. A numeric
     *                                       string is accepted because a form
     *                                       request hands back what was typed, and
     *                                       rejecting "3" as not-an-integer would
     *                                       be pedantry at the boundary.
     *
     * @throws ValidationException
     */
    public function resolve(
        Company $company,
        int|string|null $currencyId,
        Carbon|string $documentDate,
        string $field = 'currency_id',
    ): TransactionCurrency {
        $base = $company->currency;

        if ($currencyId === null || $currencyId === '') {
            return TransactionCurrency::base($base);
        }

        $currency = Currency::query()->find((int) $currencyId);

        if ($currency === null) {
            throw ValidationException::withMessages([
                $field => 'The selected currency does not exist.',
            ]);
        }

        if (! $currency->is_active) {
            /*
             * A deactivated currency may not price a NEW document. It remains
             * perfectly readable for documents that already used it, which is the
             * distinction deactivation exists to draw: stop it being chosen, do not
             * erase what it meant.
             */
            throw ValidationException::withMessages([
                $field => "Currency [{$currency->code}] exists but is inactive.",
            ]);
        }

        $base = $company->currency;

        if ($base === null) {
            throw ValidationException::withMessages([
                $field => 'This company has no base currency configured, so a foreign currency cannot be used. '
                    .'Set the company base currency first.',
            ]);
        }

        if ((int) $currency->getKey() === (int) $base->getKey()) {
            /*
             * The client chose the company's own base currency explicitly. Treated
             * as base, not as a foreign currency at rate 1.
             *
             * This matters because the two are stored differently - base is
             * currency_id NULL and rate NULL, foreign is a rate of 1 - and a
             * document that said "USD" when the base is USD would otherwise produce
             * journal lines with a rate on them, implying a quotation that never
             * happened. Normalising here means the stored shape depends on what is
             * true rather than on which field the client happened to fill in.
             */
            return TransactionCurrency::base($base);
        }

        $rate = $this->rates->rateOrFail($company, $currency, $base, $documentDate, $field);

        return TransactionCurrency::foreign($currency, $rate->rate(), $base);
    }

    /**
     * Assert an account may be used with a transaction currency.
     *
     * Permissive by default: an account with no declared currency accepts anything,
     * so an existing chart of accounts needs no changes to become
     * multi-currency-capable. An account that DOES declare one must match exactly -
     * "close enough" would defeat the point of declaring it.
     *
     * The comparison is against effectiveCurrency(), not the transaction currency.
     * For a base-currency document that is the company's own base currency, so an
     * account declared "IDR only" accepts it. Testing the raw transaction currency
     * would compare the base currency id against a null, refuse every
     * base-currency document posted to a currency-restricted account, and make the
     * restriction unusable for the single case a single-currency company cares about.
     *
     * A BASE-currency line is accepted by any account, whatever the account
     * declares. This is not a loosening so much as the rule the ledger already
     * follows: the restriction governs how an account may be DENOMINATED, and a
     * base line makes no claim about denomination - it is already in the ledger's
     * own units. JournalService::assertPersistedFxValid has always worked this way,
     * so without this the draft path would refuse entries the posting path accepts.
     *
     * Settlement is what makes the exemption necessary rather than merely tidy.
     * Settling a USD invoice whose receivable was booked at 2.5 means clearing
     * 100.00 USD's CARRYING base of 250.00 while the receipt converts at 3.0. The
     * clearing leg is 250.00 by accounting necessity - the receivable must be
     * relieved at what it was carried at, and the 50.00 gap is realized gain - so it
     * is a base amount against an account declared USD, and no foreign line at the
     * settlement rate could express it. Refusing that leg would make foreign
     * settlement impossible on any chart that declares its currencies.
     *
     * @throws ValidationException
     */
    public function assertAccountAccepts(
        Account $account,
        TransactionCurrency $transactionCurrency,
        string $field,
    ): void {
        if (! $transactionCurrency->isForeign()) {
            return;
        }

        $required = $transactionCurrency->effectiveCurrency()?->getKey();

        if ($account->acceptsCurrency($required === null ? null : (int) $required)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => sprintf(
                'Account [%s %s] holds [%s] and cannot be used with [%s].',
                $account->code,
                $account->name,
                $account->currency?->code ?? 'any currency',
                $transactionCurrency->effectiveCode(),
            ),
        ]);
    }

    /**
     * Convert a transaction-currency amount to base at this context's rate.
     *
     * A named wrapper over the value object's own method, so that a posting service
     * never calls Rate::applyTo() directly and there is one place to look for "how
     * is an amount converted".
     */
    public function toBase(Money $amount, TransactionCurrency $transactionCurrency): Money
    {
        return $transactionCurrency->convertToBase($amount);
    }

    /**
     * Convert an amount at a rate that was already recorded, never at today's.
     *
     * The counterpart to toBase(), and the one that matters for settlement. toBase()
     * answers "what is this worth NOW", which is correct for a document being priced
     * on its own date. This answers "what was this worth when it was booked", which
     * is the question a realized exchange gain or loss is made of.
     *
     * The distinction is not stylistic. Re-resolving by date would mean an exchange
     * rate row edited after a document was posted silently restating the base value
     * of that document, and a settlement six months later would report its gain
     * against a rate the company never used. A null carrying rate means the document
     * was booked in base currency, so its carrying value is the amount itself.
     */
    public function carryingBase(Money $amount, ?Rate $carryingRate): Money
    {
        return $carryingRate?->applyTo($amount) ?? $amount;
    }

    /**
     * Describe one journal line in the transaction currency.
     *
     * THE BRIDGE BETWEEN A DOCUMENT AND THE LEDGER, AND THE REASON IT HAS NO
     * debit/credit KEY AT ALL
     *
     * A posting service knows its amounts in the document's currency - it read them
     * off the document's own lines - and has no business converting them. If it
     * did, six posting services would each resolve a rate, each round it, and each
     * arrive at a base figure that could differ from the others' by a unit in the
     * last place. So the payload it builds carries the FOREIGN side and nothing
     * else, and JournalService::resolveLines() derives the base side.
     *
     * The absence of a `debit` key is deliberate and load-bearing: JournalService
     * rejects a line that states both a base and a foreign amount, so a posting
     * service physically cannot pass a converted figure even by accident. Making
     * the wrong thing unrepresentable beats documenting the right thing.
     *
     * A base-currency context produces `currency_id => null` and a null rate, which
     * is precisely the shape Phase 13 wrote - so a single-currency document takes
     * this path and gets a base line, with no branch of its own anywhere.
     *
     * @return array<string, mixed>
     */
    public function journalLine(
        TransactionCurrency $transactionCurrency,
        int $accountId,
        string $description,
        Money $amount,
        bool $isDebit,
    ): array {
        return [
            'account_id' => $accountId,
            'description' => $description,
            'currency_id' => $transactionCurrency->currency?->getKey(),
            'foreign_debit' => $isDebit ? $amount->toDatabase() : null,
            'foreign_credit' => $isDebit ? null : $amount->toDatabase(),
            'exchange_rate' => $transactionCurrency->rateToPersist(),
        ];
    }

    /**
     * The same line, described with base amounts already in place.
     *
     * For the lines that are genuinely base-currency by nature rather than by
     * omission: a company that has not configured a base currency books in its own
     * amounts at an implicit rate of 1, and a document carrying no currency is
     * already fully expressed in base terms. Emitting `debit`/`credit` directly is
     * what lets those documents reach the journal through the same builder as
     * everything else instead of through a second, base-only code path.
     *
     * @return array<string, mixed>
     */
    public function baseJournalLine(
        int $accountId,
        string $description,
        Money $amount,
        bool $isDebit,
    ): array {
        return [
            'account_id' => $accountId,
            'description' => $description,
            'debit' => $isDebit ? $amount->toDatabase() : '0',
            'credit' => $isDebit ? '0' : $amount->toDatabase(),
        ];
    }

    /**
     * The document's base totals, as the ledger recorded them.
     *
     * WHY A DOCUMENT READS ITS FIGURES BACK OUT OF THE JOURNAL
     *
     * Because the alternative is converting the document's totals a second time, in
     * a second place, and trusting the two conversions to agree. They would agree
     * today, because both call toBase() - and that is exactly the kind of agreement
     * that survives until someone adds a rounding adjustment to one of them. A
     * document whose base_tax_total disagreed with the tax credit in its own
     * journal by one unit in the last place would send the tax report and the
     * ledger to two different answers with no way to tell which was right.
     *
     * So the journal is the authority, and the document records what the journal
     * actually booked. This requires the document's journal to be created before
     * its totals are written, which is the order every posting service already
     * used.
     *
     * @param  Collection<int, JournalLine>  $lines
     * @return Money|null null when the account is not on the journal at all
     */
    public function baseAmountBooked(Collection $lines, int $accountId): ?Money
    {
        $matching = $lines->filter(fn ($line) => (int) $line->account_id === $accountId);

        if ($matching->isEmpty()) {
            return null;
        }

        return $matching->reduce(
            fn (Money $carry, $line) => $carry->plus($line->amount()),
            Money::zero()
        );
    }

    /**
     * Resolve several currencies against one company and date in a single query.
     *
     * For a document whose lines may each be in a different currency. A currency with
     * no rate is simply absent from the returned array, rather than raising: the
     * caller decides whether one missing rate among fifty lines is fatal, and a
     * resolver that threw here would remove that choice.
     *
     * @param  Collection<int, Currency>  $currencies
     * @return array<string, TransactionCurrency> keyed by currency code
     */
    public function resolveMany(
        Company $company,
        Collection $currencies,
        Carbon|string $documentDate,
    ): array {
        $base = $company->currency;

        if ($base === null) {
            return [];
        }

        $rates = $this->rates->resolveMany($company, $currencies, $documentDate);

        $resolved = [];

        foreach ($currencies as $currency) {
            if ((int) $currency->getKey() === (int) $base->getKey()) {
                $resolved[$currency->code] = TransactionCurrency::base($base);

                continue;
            }

            $rate = $rates[$currency->code] ?? null;

            if ($rate === null) {
                continue;
            }

            $resolved[$currency->code] = TransactionCurrency::foreign($currency, $rate, $base);
        }

        return $resolved;
    }
}
