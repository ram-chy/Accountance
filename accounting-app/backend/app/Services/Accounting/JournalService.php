<?php

namespace App\Services\Accounting;

use App\Enums\JournalSource;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Money;
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
 */
class JournalService
{
    public function __construct(
        private readonly JournalNumberSequence $numbers,
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

            $this->replaceLines($journal, $company, $data['lines'] ?? []);

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
                $this->replaceLines($fresh, $company, $data['lines']);
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
    private function replaceLines(Journal $journal, Company $company, array $lines): void
    {
        $structure = $this->validateStructure($lines);

        if (! $structure['valid']) {
            throw ValidationException::withMessages($structure['errors']);
        }

        $this->assertAccountsBelongToCompany($company, $lines);

        $journal->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $debit = $this->parseAmount($line['debit'] ?? '0');
            $credit = $this->parseAmount($line['credit'] ?? '0');

            $journalLine = new JournalLine([
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? null,
                'debit' => $debit->toDatabase(),
                'credit' => $credit->toDatabase(),
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
     * @throws ValidationException
     */
    private function assertAccountsBelongToCompany(Company $company, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $requested = collect($lines)->pluck('account_id')->unique()->filter()->values();

        if ($requested->isEmpty()) {
            return;
        }

        $found = Account::query()
            ->where('company_id', $company->getKey())
            ->whereIn('id', $requested)
            ->pluck('id');

        $missing = $requested->diff($found)->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'lines' => 'One or more accounts do not belong to the active company.',
            ]);
        }
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
}
