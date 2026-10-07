<?php

namespace App\Services\Accounting\CashBank;

use App\Enums\JournalSource;
use App\Enums\PaymentStatus;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\Currency\DocumentCurrencyService;
use App\Services\Accounting\Currency\TransactionCurrency;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posting a cash/bank transaction into the accounting record.
 *
 * One transaction, one journal, two lines:
 *
 *   Dr destination_account_id   amount
 *       Cr source_account_id   amount
 *
 * That is the whole entry, for all three types. A deposit is the same shape as a
 * transfer because money entering cash from an equity account and money entering
 * cash from a bank account are the same accounting event; the difference is only
 * what kind of account the other side happens to be. A withdrawal is likewise the
 * mirror image of a transfer, and the brief's warning against posting every
 * withdrawal to an expense account is respected by construction: the destination
 * is whatever account the user named, and if they named the bank that is exactly
 * what gets debited.
 *
 * Two rules this service deliberately does not own, because the posting engine
 * already enforces them and a second implementation would be a second answer:
 *
 *   - Whether the entry balances. The debit and credit are written from the same
 *     stored amount, but they are written as two separate values rather than one
 *     reused variable, so that JournalService validates the result rather than
 *     this service assuming it.
 *   - Whether the date falls in an open period, whether the accounts are active,
 *     and what status the journal ends up with. Those belong to
 *     JournalPostingService, which is the only thing in this application allowed
 *     to set journals.status = POSTED.
 *
 * The account pair is re-validated here even though it was validated when the
 * draft was saved, for the same reason Phase 5 does it: between saving a draft
 * and posting it, the destination account may have been deactivated or had its
 * cash/bank classification removed, and posting would otherwise create a journal
 * referring to an account that is no longer eligible for the movement.
 *
 * Phase 14 adds a third re-validation: the accounts are re-checked against the
 * currency they now have to hold, and the rate is re-resolved for the posting
 * date rather than taken from the draft. A foreign movement produces two journal
 * lines in the transaction currency at one rate, and JournalService derives the
 * base side - which is why the entry still balances to the penny. A movement in
 * base currency produces exactly the two lines it always did, with every FX column
 * NULL, so nothing about the single-currency case changes.
 */
class CashBankPostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
        private readonly DocumentCurrencyService $currencies,
    ) {}

    /**
     * @throws ValidationException
     * @throws ConflictException
     */
    public function post(CashBankTransaction $transaction, User $actor): CashBankTransaction
    {
        /*
         * One transaction covering the journal creation, the posting and the
         * document update. If the journal commits and the document update fails,
         * everything rolls back; if the document is saved and the posting fails,
         * likewise. There is no partial outcome to reconcile afterwards.
         */
        return DB::transaction(function () use ($transaction, $actor) {
            $fresh = CashBankTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * Rejecting a second post is the requirement, and this is the check
             * that makes it hold under concurrency: the status is read under the
             * same lock that a concurrent post would take, so of two simultaneous
             * requests exactly one sees DRAFT.
             */
            if ($fresh->status->isPosted()) {
                throw new ConflictException(
                    message: 'This cash/bank transaction is already posted. '
                        .'Record a reversing transaction instead of posting it again.',
                    errors: [
                        'transaction' => ['This cash/bank transaction is already posted. '
                            .'Record a reversing transaction instead of posting it again.'],
                    ],
                );
            }

            /*
             * A journal on a draft is not an expected state - CashBankTransactionService
             * never writes one, and only this method does. Treating it as a conflict
             * rather than proceeding is the safer of the two: posting on top of an
             * existing journal is exactly the "second journal for one transaction"
             * failure this whole design exists to prevent, and stopping is
             * recoverable while a duplicate is not.
             */
            if ($fresh->journal_id !== null) {
                throw new ConflictException(
                    message: 'This cash/bank transaction is already linked to journal '
                        .'#'.$fresh->journal_id.'. It cannot be posted again.',
                    errors: [
                        'transaction' => ['This cash/bank transaction is already linked to a journal. '
                            .'It cannot be posted again.'],
                    ],
                );
            }

            /*
             * Re-resolve both accounts under the lock. A transfer requires both to
             * still be cash/bank; a deposit or withdrawal requires the same one
             * side to be eligible and leaves the offset as a plain active account,
             * because a user who books a bank charge to an expense account must
             * not be told that account is the wrong type.
             */
            $type = $fresh->transaction_type;

            if ($type->requiresBothAccountsCashBank()) {
                $source = $this->accounts->cashBank(
                    $fresh->company,
                    $fresh->source_account_id,
                    'source_account_id'
                );
                $destination = $this->accounts->cashBank(
                    $fresh->company,
                    $fresh->destination_account_id,
                    'destination_account_id'
                );
            } elseif ($type->cashBankSide() === 'source_account_id') {
                $source = $this->accounts->cashBank(
                    $fresh->company,
                    $fresh->source_account_id,
                    'source_account_id'
                );
                $destination = $this->accounts->offset(
                    $fresh->company,
                    $fresh->destination_account_id,
                    'destination_account_id'
                );
            } else {
                $destination = $this->accounts->cashBank(
                    $fresh->company,
                    $fresh->destination_account_id,
                    'destination_account_id'
                );
                $source = $this->accounts->offset(
                    $fresh->company,
                    $fresh->source_account_id,
                    'source_account_id'
                );
            }

            if ($source->getKey() === $destination->getKey()) {
                throw ValidationException::withMessages([
                    'destination_account_id' => 'The source and destination accounts must be different.',
                ]);
            }

            $this->accounts->assertSameDenomination($source, $destination, $type);

            /*
             * The rate in force on the day the money moves, resolved HERE for the
             * same reason the receipt posting service resolves its own: the draft's
             * rate is a preview of a rate table that is still editable, and this is
             * the last moment the movement can be priced.
             */
            $context = $this->currencies->resolve(
                $fresh->company,
                $fresh->currency_id,
                $fresh->transaction_date->toDateString(),
                'currency_id',
            );

            $this->currencies->assertAccountAccepts($source, $context, 'source_account_id');
            $this->currencies->assertAccountAccepts($destination, $context, 'destination_account_id');

            $amount = $fresh->amountMoney();
            $number = $fresh->transaction_number;
            $description = $this->describe($fresh, $source->code, $destination->code);

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $fresh->transaction_date->toDateString(),
                    'description' => $description,
                    'reference' => $fresh->reference ?: $number,
                    'source_type' => JournalSource::CashBankTransaction->value,
                    'source_id' => $fresh->getKey(),
                    'lines' => $this->lines($context, $destination, $source, $amount, $description),
                ],
            );

            // The only thing in the application permitted to set status = POSTED.
            $this->posting->post($journal, $actor, 'transaction_date');

            /*
             * base_amount is the figure the ledger booked, read back out of the
             * journal rather than converted a second time here. Both legs carry the
             * same converted amount - the entry is Dr destination / Cr source at one
             * rate - so the destination leg is as good a source as any.
             */
            $booked = $this->currencies->baseAmountBooked(
                $journal->lines,
                $destination->getKey(),
            );

            $fresh->forceFill([
                'status' => PaymentStatus::Posted->value,
                'journal_id' => $journal->getKey(),
                'base_amount' => ($booked ?? $amount)->toDatabase(),
                'exchange_rate' => $context->rateToPersist(),
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
            ])->save();

            return $fresh->refresh();
        });
    }

    /**
     * The two journal lines: destination debited, source credited, same money.
     *
     * There is no third line here, and that is worth being explicit about.
     * Cash/bank is the one place in this application where foreign currency moves
     * *between* accounts of the same currency rather than being converted, so there
     * is no difference to book: a transfer of 1,000 EUR at 16,000 is 16,000,000 on
     * both sides. The realized FX that appears when foreign money is converted - a
     * receipt against a foreign invoice, a payment against a foreign bill - has no
     * analogue here, and manufacturing one would post an FX gain the company did
     * not have.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(
        TransactionCurrency $context,
        Account $destination,
        Account $source,
        Money $amount,
        string $description,
    ): array {
        $build = $context->isForeign()
            ? fn (Account $account, bool $isDebit) => $this->currencies->journalLine(
                $context, $account->getKey(), $description, $amount, $isDebit
            )
            : fn (Account $account, bool $isDebit) => $this->currencies->baseJournalLine(
                $account->getKey(), $description, $amount, $isDebit
            );

        return [$build($destination, true), $build($source, false)];
    }

    /**
     * A journal description that says what the entry was for.
     *
     * The reference is used when there is one, because a bank reference is the
     * thing a person looking at the ledger will recognise; the account codes are
     * included because the ledger line's own description is the only place a
     * reader can tell a transfer to the bank from one to petty cash.
     */
    private function describe(CashBankTransaction $transaction, string $sourceCode, string $destinationCode): string
    {
        $type = strtolower($transaction->transaction_type->value);

        return sprintf(
            '%s %s: %s -> %s',
            ucfirst($type),
            $transaction->transaction_number,
            $sourceCode,
            $destinationCode
        );
    }
}
