<?php

namespace App\Services\Accounting;

use App\Enums\JournalSource;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Accounting\Currency\DocumentCurrencyService;
use App\Support\Money;
use App\Support\Rate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Draft journal creation, editing and structural validation.
 *
 * This service owns drafts only. The transition to POSTED belongs to
 * JournalPostingService, and nothing here writes `status` - keeping the draft
 * lifecycle and the posting lifecycle in separate services is what makes
 * "posted journals are immutable" checkable by reading two files rather than
 * auditing every write in one.
 *
 * PHASE 14: WHERE THE BASE AMOUNTS ON A LINE COME FROM
 *
 * Every line reaching this service is normalised through resolveLines() before
 * anything is validated or written, and that normalisation is the only place in
 * the application that turns a foreign amount into a base one. The consequences
 * are deliberate:
 *
 *   - A caller supplies `foreign_debit`/`foreign_credit` for a foreign line and
 *     never the base amount. The base column is derived here, at the rate
 *     resolved for the journal's own date. A payload carrying both is refused
 *     rather than reconciled, because "which one won" is not a question with an
 *     answer worth having.
 *   - The derived base amount is what validateStructure() then balances, so an
 *     unbalanced entry is reported as unbalanced instead of being forced to
 *     balance by trusting a number the client sent.
 *   - The rate is snapshotted onto the line, so the entry stays self-describing
 *     after the rate table changes underneath it.
 *
 * A line with no foreign amount and no currency is untouched by all of this and
 * is written exactly as Phase 13 wrote it, which is what keeps that suite green.
 */
class JournalService
{
    public function __construct(
        private readonly JournalNumberSequence $numbers,
        private readonly AccountingPeriodService $periods,
        private readonly DocumentCurrencyService $currencies,
    ) {}

    /**
     * Create a draft journal with its lines.
     *
     * Wrapped in a transaction because a journal and its lines are multiple rows:
     * a crash between them would leave a header with no lines, which is exactly
     * the "inconsistent state" the spec forbids.
     *
     * @throws ValidationException
     */
    public function createDraft(Company $company, User $actor, array $data): Journal
    {
        $this->assertLineCountWithinLimit($data['lines'] ?? []);

        return DB::transaction(function () use ($company, $actor, $data) {
            // Must run inside the transaction: the counter row lock is held until
            // commit, which is what makes concurrent numbering safe.
            $number = $this->numbers->nextFor($company);

            /*
             * forceFill rather than create(), because four of these columns are
             * server-assigned and must never be mass-assignable: company_id comes
             * from the authenticated context, journal_number from the sequence,
             * created_by from the token, and status from the lifecycle. Passing
             * them through fill() would require adding them to $fillable, which is
             * exactly the change that would let a client choose them.
             */
            $journal = new Journal([
                'journal_date' => $data['journal_date'],
                'description' => $data['description'] ?? null,
                'reference' => $data['reference'] ?? null,
                'source_type' => $data['source_type'] ?? JournalSource::Manual->value,
                'source_id' => $data['source_id'] ?? null,
            ]);

            $journal->forceFill([
                'company_id' => $company->getKey(),
                'journal_number' => $number,
                'created_by' => $actor->getKey(),
                'status' => JournalStatus::Draft->value,
            ])->save();

            $this->replaceLines(
                $journal,
                $company,
                (string) $journal->journal_date,
                $data['lines'] ?? []
            );

            return $journal->refresh();
        });
    }

    /**
     * Update a draft journal.
     *
     * Refuses outright if the journal is posted rather than filtering it out of
     * a query: the caller has already decided this is an update, and silently
     * treating a posted journal as "not found" hides the real reason.
     *
     * @throws ValidationException
     */
    public function updateDraft(Journal $journal, Company $company, array $data): Journal
    {
        $this->assertLineCountWithinLimit($data['lines'] ?? []);

        return DB::transaction(function () use ($journal, $company, $data) {
            /*
             * The status is re-read under a row lock, not taken from the instance
             * that routing handed us.
             *
             * A pre-transaction check would leave a window: a concurrent POST
             * commits between that check and this transaction, and the update then
             * writes to a journal that is already in the permanent record. The
             * lock is the same one JournalPostingService takes, so the two
             * operations serialise and exactly one of them sees status = DRAFT.
             */
            $fresh = $this->lockDraftForEditing($journal);

            /*
             * Phase 8: the accounting date may not be moved into a closed period.
             *
             * Checked only when journal_date is actually in the payload. A draft
             * created while its month was open and never re-dated stays editable
             * for its description and lines - a draft is not yet accounting data,
             * and locking it entirely the day its month closes would make a typo in
             * the description unfixable except by delete-and-recreate. What is
             * refused is moving accounting data into a closed date.
             *
             * This runs inside the transaction and after the draft lock, so the
             * check cannot be overtaken by a concurrent close.
             */
            if (array_key_exists('journal_date', $data)) {
                $this->periods->assertDateNotClosed(
                    $company,
                    Carbon::parse($data['journal_date']),
                    'journal_date',
                );
            }

            $fresh->fill(array_filter([
                'journal_date' => $data['journal_date'] ?? null,
                'description' => $data['description'] ?? null,
                'reference' => $data['reference'] ?? null,
            ], fn ($value) => $value !== null));

            if (array_key_exists('source_type', $data)) {
                $fresh->source_type = $data['source_type'];
            }

            if (array_key_exists('source_id', $data)) {
                $fresh->source_id = $data['source_id'];
            }

            $fresh->save();

            if (array_key_exists('lines', $data)) {
                // Full replace rather than a diff. A partial update would have to
                // express "remove line 3" with no way to be sure the client meant
                // that, and a whole-entry save is how accountants expect a
                // journal edit to behave.
                //
                // The rate is resolved against the date the journal will carry once
                // this save lands, not against the date it carried on entry - an
                // entry that is both re-dated and re-currency-denominated in one
                // edit must be priced at the rate of the date it is being moved to.
                $this->replaceLines($fresh, $company, (string) $fresh->journal_date, $data['lines']);
            }

            return $fresh->refresh();
        });
    }

    /**
     * Delete a draft journal and its lines.
     *
     * The journal number is NOT reused afterwards; see JournalNumberSequence.
     *
     * @throws ValidationException
     */
    public function deleteDraft(Journal $journal): void
    {
        // Locking for the same reason as updateDraft: a delete that raced a post
        // must not remove an entry that has already become part of the record.
        DB::transaction(function () use ($journal) {
            $fresh = $this->lockDraftForEditing($journal);

            // journal_lines cascade from the journal via the FK.
            $fresh->delete();
        });
    }

    /**
     * Re-read a journal with `FOR UPDATE` and assert it is still a draft.
     *
     * Returning the locked instance rather than mutating the caller's copy is
     * deliberate: the caller then writes against the row it actually verified,
     * and a stale in-memory status cannot leak into the update.
     *
     * @throws ValidationException
     */
    private function lockDraftForEditing(Journal $journal): Journal
    {
        $locked = Journal::query()
            ->whereKey($journal->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertDraftIsEditable($locked);

        return $locked;
    }

    /**
     * Validate a journal's internal structure: line count, one-sidedness, and
     * whether the debits equal the credits.
     *
     * Reports every structural problem at once rather than the first one, so a
     * user fixing an entry does not have to resubmit five times to discover five
     * separate mistakes.
     *
     * @param  iterable<int, array<string, mixed>>  $lines
     * @return array{valid: bool, errors: array<string, array<int, string>>, total_debit: string, total_credit: string, difference: string}
     */
    public function validateStructure(iterable $lines): array
    {
        $errors = [];
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();
        $count = 0;

        foreach ($lines as $index => $line) {
            $count++;
            $label = "lines.{$index}";

            try {
                $debit = $this->parseAmount($line['debit'] ?? '0');
                $credit = $this->parseAmount($line['credit'] ?? '0');
            } catch (\InvalidArgumentException $e) {
                $errors[$label][] = $e->getMessage();

                continue;
            }

            if ($debit->isNegative() || $credit->isNegative()) {
                $errors[$label][] = 'A journal line cannot have a negative amount. '
                    .'Record the opposite side on the other column instead.';
            }

            if ($debit->isPositive() && $credit->isPositive()) {
                $errors[$label][] = 'A journal line cannot have both a debit and a credit. '
                    .'Split it into two lines.';
            }

            if ($debit->isZero() && $credit->isZero()) {
                $errors[$label][] = 'A journal line must have an amount greater than zero on one side.';
            }

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
        }

        if ($count < 2) {
            $errors['lines'][] = 'A journal must have at least two lines. '
                .'Every entry needs both a debit and a credit.';
        }

        if ($count > $this->maxLines()) {
            $errors['lines'][] = 'A journal may not have more than '
                .$this->maxLines().' lines.';
        }

        /*
         * The central rule. Only evaluated when no line-level error exists: if a
         * line failed to parse or has an illegal shape, the totals are not a
         * meaningful summary of the entry, so reporting an imbalance on top
         * would be a second misleading complaint about a single problem.
         */
        if ($errors === [] && ! $totalDebit->equals($totalCredit)) {
            $errors['lines'][] = sprintf(
                'The journal is not balanced. Total debit is %s and total credit is %s, '
                .'a difference of %s.',
                $totalDebit,
                $totalCredit,
                $totalDebit->minus($totalCredit)
            );
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'total_debit' => (string) $totalDebit,
            'total_credit' => (string) $totalCredit,
            'difference' => (string) $totalDebit->minus($totalCredit),
        ];
    }

    /**
     * Normalise a payload's lines into the exact shape that gets persisted.
     *
     * This is the single point where a foreign amount becomes a base amount, and
     * the single point where a rate is looked up for a journal. It runs before
     * validateStructure() so that the balance check is performed against derived
     * base amounts rather than against whatever the client claimed they were -
     * a check against client-supplied figures can only ever confirm that the
     * client balanced its own numbers, not that the entry balances.
     *
     * Errors from the currency layer are collected per field and raised together
     * with the structural ones afterwards, for the same reason validateStructure()
     * batches: a user fixing an entry should not need five submissions to discover
     * five separate mistakes. The two groups are kept apart in the output so a
     * currency problem and a balance problem stay distinguishable in the response.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function resolveLines(Company $company, Carbon|string $date, array $lines): array
    {
        $accounts = $this->accountsBelongingToCompany($company, $lines);

        $errors = [];
        $prepared = [];

        foreach (array_values($lines) as $index => $line) {
            $label = "lines.{$index}";

            $lineErrors = [];

            $preparedLine = $this->prepareLine($company, $date, $label, $line, $accounts, $lineErrors);

            if ($lineErrors !== []) {
                $errors = array_merge_recursive($errors, $lineErrors);

                continue;
            }

            $prepared[] = $preparedLine;
        }

        /*
         * Structural validation of whatever survived normalisation.
         *
         * Skipped when a line failed to normalise, because dropping a line from the
         * totals would understate both sides and produce an imbalance message that
         * describes the normaliser rather than the entry. The currency errors are
         * the ones worth fixing first anyway.
         */
        if ($errors === []) {
            $structure = $this->validateStructure($prepared);

            if (! $structure['valid']) {
                $errors = array_merge($errors, $structure['errors']);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $prepared;
    }

    /**
     * Normalise one line, collecting rather than throwing.
     *
     * The shape it returns always carries `debit` and `credit` as canonical decimal
     * strings, whether they came from the payload (base currency) or from a
     * conversion (foreign), so validateStructure() has a single input format and no
     * branch on whether the entry happens to be multi-currency.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, Account>  $accounts
     * @param  array<string, array<int, string>>  $errors  collected in place
     * @return array<string, mixed>
     */
    private function prepareLine(
        Company $company,
        Carbon|string $date,
        string $label,
        array $line,
        array $accounts,
        array &$errors,
    ): array {
        $accountId = $line['account_id'] ?? null;
        $account = $accountId === null ? null : ($accounts[(int) $accountId] ?? null);

        $transaction = $this->currencies->resolve(
            $company,
            $line['currency_id'] ?? null,
            $date,
            "{$label}.currency_id",
        );

        $foreignDebit = $line['foreign_debit'] ?? null;
        $foreignCredit = $line['foreign_credit'] ?? null;
        $suppliedRate = $line['exchange_rate'] ?? null;

        /*
         * No foreign amount: a base-currency line, whatever currency_id says.
         *
         * `resolve()` has already normalised an explicit choice of the company's own
         * base currency to a base context, so reaching here with `isForeign()` true
         * means the client named a genuinely foreign currency and then declined to
         * say how much of it there is. There is no base amount to derive it from,
         * and accepting the base columns instead would mean re-introducing the
         * "which amount did they mean" ambiguity one branch below.
         */
        if ($foreignDebit === null && $foreignCredit === null) {
            if ($suppliedRate !== null) {
                $errors["{$label}.exchange_rate"][] = 'An exchange rate applies only to a foreign amount. '
                    .'Supply foreign_debit or foreign_credit, or remove the rate from a base-currency line.';
            }

            if ($transaction->isForeign()) {
                $errors["{$label}.foreign_debit"][] = sprintf(
                    'This line is in [%s], so it must state its amount in that currency. '
                    .'Use foreign_debit or foreign_credit; the base amount is derived from the rate.',
                    $transaction->code(),
                );

                return [];
            }

            if ($account !== null) {
                $this->currencies->assertAccountAccepts($account, $transaction, "{$label}.account_id");
            }

            try {
                $debit = $this->parseAmount($line['debit'] ?? '0');
                $credit = $this->parseAmount($line['credit'] ?? '0');
            } catch (\InvalidArgumentException $e) {
                $errors["{$label}.debit"][] = $e->getMessage();

                return [];
            }

            return $this->baseLine($line, $debit, $credit);
        }

        /*
         * A foreign amount was supplied. Refusing a base amount alongside it is the
         * whole point: the two must agree by construction, and a payload carrying
         * both is asking this service to pick a winner between two claims about the
         * same money.
         */
        try {
            $claimedDebit = $this->parseAmount($line['debit'] ?? '0');
            $claimedCredit = $this->parseAmount($line['credit'] ?? '0');
        } catch (\InvalidArgumentException $e) {
            $errors["{$label}.debit"][] = $e->getMessage();

            return [];
        }

        if ($claimedDebit->isPositive() || $claimedCredit->isPositive()) {
            $errors["{$label}.debit"][] = 'This line states a foreign amount, so its base amount is derived '
                .'from the exchange rate. Supply either the foreign amount or the base amount, not both.';
        }

        $foreign = $this->parseForeignAmount($label, $foreignDebit, $foreignCredit, $errors);

        if ($foreign === null) {
            return [];
        }

        $isDebit = $foreignDebit !== null;

        /*
         * A base-currency context with a foreign amount on it is normalised rather
         * than refused: the client filled in the foreign columns because it had
         * them, but the currency it named resolves to the company's own, and storing
         * a rate of 1 for that would assert a quotation nobody made. The foreign
         * columns are dropped, the base amount is the amount given, and the stored
         * shape describes what is true.
         */
        if (! $transaction->isForeign()) {
            $rateError = $this->rateMatches($suppliedRate, $transaction->rate);

            if ($rateError !== null) {
                $errors["{$label}.exchange_rate"][] = $rateError;

                return [];
            }

            if ($account !== null) {
                $this->currencies->assertAccountAccepts($account, $transaction, "{$label}.account_id");
            }

            return $this->baseLine($line, $isDebit ? $foreign : Money::zero(), $isDebit ? Money::zero() : $foreign);
        }

        $rateError = $this->rateMatches($suppliedRate, $transaction->rate);

        if ($rateError !== null) {
            $errors["{$label}.exchange_rate"][] = $rateError;

            return [];
        }

        if ($account !== null) {
            $this->currencies->assertAccountAccepts($account, $transaction, "{$label}.account_id");
        }

        /*
         * The one conversion. `toBase()` rather than Rate::applyTo() directly, so
         * that the value written here is the same value the database CHECK verifies
         * and the same value the reports read back - one call, one rounding point.
         */
        $base = $this->currencies->toBase($foreign, $transaction);

        return [
            'account_id' => $accountId,
            'description' => $line['description'] ?? null,
            'debit' => ($isDebit ? $base : Money::zero())->toDatabase(),
            'credit' => ($isDebit ? Money::zero() : $base)->toDatabase(),
            'currency_id' => $transaction->currency?->getKey(),
            'foreign_debit' => $isDebit ? $foreign->toDatabase() : null,
            'foreign_credit' => $isDebit ? null : $foreign->toDatabase(),
            'exchange_rate' => $transaction->rateToPersist(),
        ];
    }

    /**
     * The stored shape of a base-currency line: FX columns all null.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function baseLine(array $line, Money $debit, Money $credit): array
    {
        return [
            'account_id' => $line['account_id'] ?? null,
            'description' => $line['description'] ?? null,
            'debit' => $debit->toDatabase(),
            'credit' => $credit->toDatabase(),
            'currency_id' => null,
            'foreign_debit' => null,
            'foreign_credit' => null,
            'exchange_rate' => null,
        ];
    }

    /**
     * The foreign amount on one side of a line, or null if it is unusable.
     *
     * One-sided and positive, mirroring the rules the database enforces on
     * foreign_debit/foreign_credit and on debit/credit. Both rules matter here
     * rather than being left to the CHECK, because the derived base amount is
     * written from this value and a two-sided or negative foreign amount would
     * produce a line that is arithmetically self-consistent and accountingly
     * meaningless.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function parseForeignAmount(string $label, mixed $debit, mixed $credit, array &$errors): ?Money
    {
        if ($debit !== null && $credit !== null) {
            $errors["{$label}.foreign_debit"][] = 'A journal line cannot have both a foreign debit and a foreign credit. '
                .'Split it into two lines.';

            return null;
        }

        try {
            $amount = $this->parseAmount($debit ?? $credit);
        } catch (\InvalidArgumentException $e) {
            $errors[$debit !== null ? "{$label}.foreign_debit" : "{$label}.foreign_credit"][] = $e->getMessage();

            return null;
        }

        if ($amount->isNegative()) {
            $errors[$debit !== null ? "{$label}.foreign_debit" : "{$label}.foreign_credit"][] = 'A foreign amount cannot be negative. '
                .'Record the opposite side on the other column instead.';

            return null;
        }

        if (! $amount->isPositive()) {
            $errors[$debit !== null ? "{$label}.foreign_debit" : "{$label}.foreign_credit"][] = 'A foreign amount must be greater than zero.';

            return null;
        }

        return $amount;
    }

    /**
     * Validate a client-supplied rate against the one resolved for the date.
     *
     * A supplied rate is optional - omitting it is the normal case, because the
     * server owns it - but when one IS sent it is treated as a claim about what the
     * conversion should produce, and a claim that disagrees with the rate in force
     * is a mistake worth reporting rather than silently overwriting. It is the one
     * place a client can notice that it expected a different rate.
     *
     * Returns an error message, or null when the line may proceed.
     */
    private function rateMatches(mixed $supplied, Rate $resolved): ?string
    {
        if ($supplied === null) {
            return null;
        }

        try {
            $rate = Rate::of($supplied);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        if ($rate->equals($resolved)) {
            return null;
        }

        return 'The supplied exchange rate does not match the rate in force for this date. '
            .'Leave the rate out to use the rate of record, or correct the date.';
    }

    /**
     * Validate a payload's lines and persist them, replacing whatever was there.
     *
     * Structure is validated *before* anything is written, so a rejected payload
     * leaves no trace. That matters for the zero-value case specifically: the
     * database CHECK constraint would reject a 0/0 line, but only after the
     * transaction had already deleted the previous lines - so the caller would
     * get a raw integrity error instead of a validation message.
     *
     * Account *activity* is deliberately not checked here; see
     * JournalPostingService. A draft may reference an account that is inactive
     * now and become usable later, and rejecting it early would stop a user
     * staging next month's entries. What must hold immediately is that every
     * referenced account exists in this company.
     *
     * @throws ValidationException
     */
    private function replaceLines(Journal $journal, Company $company, Carbon|string $date, array $lines): void
    {
        $prepared = $this->resolveLines($company, $date, $lines);

        $journal->lines()->delete();

        foreach (array_values($prepared) as $index => $line) {
            $journalLine = new JournalLine([
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'currency_id' => $line['currency_id'],
                'foreign_debit' => $line['foreign_debit'],
                'foreign_credit' => $line['foreign_credit'],
                'exchange_rate' => $line['exchange_rate'],
                'line_number' => $index + 1,
            ]);

            // journal_id is not fillable: a line belongs to the journal being
            // written, never to one the caller names.
            $journalLine->forceFill(['journal_id' => $journal->getKey()])->save();
        }
    }

    /**
     * Every referenced account must exist and belong to this company.
     *
     * This is the cross-tenant check. A company-scoped query by id returning
     * fewer accounts than lines requested means the client asked for an account
     * from another company, and the rejection is deliberately generic about it.
     *
     * Returned rather than merely validated because the currency layer needs the
     * account itself, to compare its declared currency against the line's - and
     * loading it once per line would mean a two-hundred-line journal issued the
     * same query two hundred times.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, Account>
     *
     * @throws ValidationException
     */
    private function accountsBelongingToCompany(Company $company, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $requested = collect($lines)->pluck('account_id')->unique()->filter()->values();

        if ($requested->isEmpty()) {
            return [];
        }

        $accounts = Account::query()
            ->with('currency')
            ->where('company_id', $company->getKey())
            ->whereIn('id', $requested)
            ->get()
            ->keyBy(fn (Account $account) => (int) $account->getKey())
            ->all();

        $missing = $requested->diff(array_keys($accounts))->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'One or more accounts do not belong to the active company.',
            ]);
        }

        return $accounts;
    }

    /**
     * @throws ValidationException
     */
    private function assertDraftIsEditable(Journal $journal): void
    {
        if ($journal->isPosted()) {
            throw ValidationException::withMessages([
                'journal' => 'This journal is posted and cannot be edited. '
                    .'Record a correcting journal instead.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertLineCountWithinLimit(array $lines): void
    {
        if (count($lines) > $this->maxLines()) {
            throw ValidationException::withMessages([
                'lines' => 'A journal may not have more than '.$this->maxLines().' lines.',
            ]);
        }
    }

    private function maxLines(): int
    {
        return (int) config('accounting.limits.max_lines_per_journal', 500);
    }

    /**
     * Parse an amount, tolerating extra precision from the client.
     *
     * @throws \InvalidArgumentException
     */
    private function parseAmount(int|float|string|null $value): Money
    {
        return Money::ofTolerant($value ?? 0);
    }

    /**
     * Assert a set of lines is structurally sound, for callers that need a hard
     * failure rather than the error array.
     *
     * Posting re-validates the persisted lines through this same method, which
     * is why the posting service does not carry its own copy of the rules.
     *
     * @throws ValidationException
     */
    public function assertStructureValid(iterable $lines): void
    {
        $result = $this->validateStructure($lines);

        if (! $result['valid']) {
            throw ValidationException::withMessages($result['errors']);
        }
    }

    /**
     * Re-check a persisted line's foreign columns against its own base amount.
     *
     * The `journal_lines_fx_consistency_check` constraint already makes an
     * inconsistent line impossible in the database, so this is not the last line of
     * defence against that - it is the defence against the *account* half of the
     * same rule, which the constraint cannot express, plus a re-assertion that a
     * draft's FX story still holds at the moment it becomes part of the record.
     *
     * Deliberately NOT re-resolving the rate from the rate table. The rate on the
     * line is a snapshot and the document that produced it may legitimately have
     * been dated months ago; re-resolving would fail the posting of a correct draft
     * because an administrator backdated a rate table entry after the fact. What
     * must hold is that the line agrees with itself, and that is entirely a
     * question about the row.
     *
     * Scoped to foreign lines, deliberately. A base-currency line's transaction
     * currency is the company's base currency - a fact about the company, not about
     * the row - and re-deciding that here would mean posting behaved differently
     * depending on a configuration change made after the draft was staged. The
     * draft-time check in resolveLines() already applied it, and that is the right
     * moment for a rule about the entry's own contents.
     *
     * @param  iterable<int, JournalLine>  $lines
     * @param  array<int, Account>  $accounts  keyed by account id
     *
     * @throws ValidationException
     */
    public function assertPersistedFxValid(iterable $lines, array $accounts): void
    {
        $errors = [];

        foreach ($lines as $line) {
            $label = 'lines.'.$line->line_number;

            $foreign = $line->foreignAmount();
            $rate = $line->exchangeRate();

            if ($foreign === null || $rate === null) {
                if ($line->isForeignCurrency() && $rate === null) {
                    $errors["{$label}.exchange_rate"][] = 'This line is in a foreign currency but carries no exchange rate.';
                }

                continue;
            }

            $expected = $rate->applyTo($foreign);

            if (! $expected->equals($line->amount())) {
                $errors["{$label}.debit"][] = sprintf(
                    'The base amount of %s does not equal the foreign amount of %s at the recorded rate of %s.',
                    $line->amount(),
                    $foreign,
                    $rate,
                );
            }

            $currency = $line->currency;

            if ($currency === null) {
                $errors["{$label}.currency_id"][] = 'This line references a currency that no longer exists.';

                continue;
            }

            $account = $accounts[(int) $line->account_id] ?? null;

            if ($account === null) {
                continue;
            }

            if ($account->acceptsCurrency((int) $currency->getKey())) {
                continue;
            }

            $errors["{$label}.account_id"][] = sprintf(
                'Account [%s %s] holds [%s] and cannot be used with [%s].',
                $account->code,
                $account->name,
                $account->currency?->code ?? 'any currency',
                $currency->code,
            );
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
