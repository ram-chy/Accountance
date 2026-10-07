<?php

namespace App\Services\Accounting;

use App\Enums\JournalStatus;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The posting engine: the only place in the application that turns a draft into
 * part of the accounting record.
 *
 * Every rule the spec lists for posting is enforced here, in the spec's order,
 * inside one database transaction that holds an exclusive lock on the journal
 * row. Nothing else may set status to POSTED - not a controller, not a future
 * business module, not a queued job. That single-writer property is what makes
 * "posted journals are immutable" an architectural fact rather than a
 * convention.
 */
class JournalPostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountingPeriodService $periods,
        private readonly AccountService $accounts,
    ) {}

    /**
     * Post a draft journal.
     *
     * The sequence is fixed and every step happens with the journal row locked,
     * so the validations cannot be invalidated by a concurrent writer between
     * the check and the write.
     *
     * $dateField is the request field a period rejection should be reported against.
     * A manual journal posts itself, so the default is right for it. A flow that
     * generates the journal from a document - an invoice, a bill, a receipt, a
     * payment, a cash/bank transaction - passes its own date field, so the user is
     * told the problem is with the invoice_date they submitted rather than with a
     * journal_date their form does not have.
     *
     * @throws ValidationException
     * @throws ConflictException when the journal is already posted
     */
    public function post(Journal $journal, User $actor, string $dateField = 'journal_date'): Journal
    {
        /*
         * posted_by is taken from the authenticated user object, never from the
         * request payload. The spec is explicit, and the reason is that an audit
         * trail recording whoever the client said posted the entry is worthless.
         */
        return DB::transaction(function () use ($journal, $actor, $dateField) {
            /*
             * 1. Re-read the journal under an exclusive row lock.
             *
             * Two concurrent POSTs for the same journal: the first acquires the
             * lock and proceeds; the second blocks here until the first commits,
             * then reads the freshly-committed POSTED row and is rejected by the
             * status check below. Without the lock both transactions would read
             * DRAFT, both would validate, and both would write - producing either
             * a lost update or two posted_at values racing. The unique index on
             * (company_id, journal_number) would NOT catch that, because both
             * writes target the same journal_number and the second would simply
             * update the same row.
             */
            $fresh = Journal::query()
                ->whereKey($journal->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Status: posting is idempotent in effect, never in state.
            if ($fresh->status->isPosted()) {
                /*
                 * ConflictException, not ValidationException.
                 *
                 * This branch is reached by a caller whose input was perfectly
                 * valid: it asked to post a journal, and the journal was already
                 * posted when the lock was acquired. The controller's early check
                 * returns the same 409 for the sequential case, and returning
                 * anything different here would make the response depend on
                 * whether the client happened to be concurrent - the same
                 * logical request answering 409 or 422 depending on timing.
                 */
                throw new ConflictException(
                    message: 'This journal is already posted. '
                        .'Record a correcting journal instead of posting again.',
                    errors: [
                        'journal' => ['This journal is already posted. '
                            .'Record a correcting journal instead of posting again.'],
                    ],
                );
            }

            /*
             * 3. Lines. Re-loaded with the lock held rather than trusting the
             * caller's relation, so the validation sees committed state and not
             * whatever the route-bound model happened to carry.
             *
             * The currency relation is eager-loaded here because the FX re-check
             * below walks every foreign line; lazy-loading it would issue one query
             * per foreign line on a posting that has already taken an exclusive lock
             * and cannot afford to be slow.
             */
            $lines = $fresh->lines()->with('currency')->get();

            // 4. Structure: at least two lines, each one-sided and non-zero.
            // 5. Balance: SUM(debit) = SUM(credit), in exact decimal.
            $this->journals->assertStructureValid($lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
            ])->all());

            // 6. Accounts must exist in this journal's company and be active.
            $accountIds = $lines->pluck('account_id')->unique();

            $journalAccounts = Account::query()
                ->with('currency')
                ->where('company_id', $fresh->company_id)
                ->whereIn('id', $accountIds)
                ->get();

            if ($journalAccounts->count() !== $accountIds->count()) {
                throw ValidationException::withMessages([
                    'lines' => 'One or more accounts on this journal do not belong to its company.',
                ]);
            }

            foreach ($journalAccounts as $account) {
                $this->accounts->assertUsableForPosting($account);
            }

            /*
             * 6b. Phase 14: a foreign line must still agree with its own rate, and
             * the account it touches must still be willing to hold that currency.
             *
             * Placed after the account lookup rather than before it, because the
             * currency check needs the accounts. It runs on the persisted rows
             * rather than on any payload, which is the point of posting: this is the
             * last moment before the entry becomes part of the permanent record, and
             * it is the last moment a still-editable draft can be caught.
             */
            $this->journals->assertPersistedFxValid(
                $lines,
                $journalAccounts->keyBy(fn (Account $account) => (int) $account->getKey())->all(),
            );

            // 7. Period: the accounting date must land in an open period.
            $this->periods->assertPostableDate(
                $fresh->company,
                Carbon::parse($fresh->journal_date),
                $dateField,
            );

            /*
             * 8. Write. One statement, so there is no window in which status is
             * POSTED but posted_at is not yet set - the journals_posted_fields_check
             * constraint would reject that anyway, which is the schema agreeing
             * with the transaction design.
             */
            $fresh->forceFill([
                'status' => JournalStatus::Posted->value,
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Assert a journal is still a draft, for callers that must not proceed if it
     * has been posted in the meantime.
     *
     * @throws ValidationException
     */
    public function assertStillDraft(Journal $journal): void
    {
        $current = Journal::query()->whereKey($journal->getKey())->first();

        if ($current === null) {
            throw ValidationException::withMessages([
                'journal' => 'This journal no longer exists.',
            ]);
        }

        if ($current->status->isPosted()) {
            throw ValidationException::withMessages([
                'journal' => 'This journal is posted and can no longer be modified.',
            ]);
        }
    }

    /**
     * Totals for a journal, computed from its persisted lines.
     *
     * Exposed so a controller can show a user why their entry is unbalanced
     * without duplicating the summation. Drafts only - a posted journal's totals
     * are fixed by definition.
     *
     * @return array{total_debit: string, total_credit: string, difference: string, balanced: bool}
     */
    public function totalsFor(Journal $journal): array
    {
        $debit = $journal->lines->reduce(
            fn ($carry, $line) => $carry->plus($line->debitAmount()),
            Money::zero()
        );

        $credit = $journal->lines->reduce(
            fn ($carry, $line) => $carry->plus($line->creditAmount()),
            Money::zero()
        );

        return [
            'total_debit' => (string) $debit,
            'total_credit' => (string) $credit,
            'difference' => (string) $debit->minus($credit),
            'balanced' => $debit->equals($credit),
        ];
    }
}
