<?php

namespace App\Services\Accounting\CashBank;

use App\Enums\CashBankTransactionType;
use App\Enums\DocumentNumberType;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use App\Services\Accounting\DocumentNumberSequence;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash/bank transactions: draft lifecycle.
 *
 * A cash/bank transaction is one operational document with three shapes:
 *
 *   Deposit     Dr destination (cash/bank)   Cr source (any account)
 *   Withdrawal  Dr destination (any account) Cr source (cash/bank)
 *   Transfer    Dr destination (cash/bank)   Cr source (cash/bank)
 *
 * Every one of them is Dr destination / Cr source. The type does not change the
 * entry - it changes which sides have to be cash/bank accounts, and that
 * difference is what this service enforces.
 *
 * The offset account on a deposit and a withdrawal is required and is never
 * inferred. "Money arrived" does not say whether it was capital introduced, a
 * loan drawn or a suspense balance being cleared, and a system that guesses
 * would eventually be wrong in a way that is very hard to find afterwards. A
 * transfer is the only type with no free choice, because both of its accounts
 * are already determined by the fact that it is a transfer.
 *
 * This service owns drafts. It writes no journal lines, sets no journal status
 * and has no posting path of its own; CashBankPostingService and
 * JournalPostingService own those, and the split is what lets a caller tell
 * which service a mistake would have to be in.
 *
 * The one thing it does know about accounting periods is the Phase 8 rule that a
 * draft may not be *re-dated* into a closed period. That is a draft-lifecycle
 * guard, not a posting check: it asks AccountingPeriodService the same question
 * every other document service asks, so there is one implementation of "is this
 * date closed" rather than one per service. Whether a transaction may post is
 * still decided only at CashBankPostingService.
 */
class CashBankTransactionService
{
    public function __construct(
        private readonly DocumentNumberSequence $numbers,
        private readonly TransactionAccountResolver $accounts,
        private readonly AccountingPeriodService $periods,
    ) {}

    /**
     * Create a draft cash/bank transaction of a given type.
     *
     * The type is a parameter rather than a validated field on purpose: it is
     * fixed by the endpoint the user called, and a payload that could name its
     * own type would let a withdrawal be stored as a deposit and post to the
     * wrong pair of accounts.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createDraft(
        Company $company,
        User $actor,
        CashBankTransactionType $type,
        array $data
    ): CashBankTransaction {
        $accounts = $this->resolveAccounts($company, $type, $data);

        return DB::transaction(function () use ($company, $actor, $type, $data, $accounts) {
            $number = $this->numbers->nextFor($company, DocumentNumberType::CashBankTransaction);

            $transaction = new CashBankTransaction([
                'transaction_date' => $data['transaction_date'],
                'source_account_id' => $accounts['source']->getKey(),
                'destination_account_id' => $accounts['destination']->getKey(),
                'amount' => Money::ofTolerant($data['amount'])->toDatabase(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            /*
             * transaction_type, company_id, transaction_number, status and the
             * user columns are all force-filled. They are absent from $fillable
             * precisely so they cannot arrive from the payload, and the only way
             * to write them is this method and CashBankPostingService.
             */
            $transaction->forceFill([
                'company_id' => $company->getKey(),
                'transaction_number' => $number,
                'transaction_type' => $type->value,
                'status' => PaymentStatus::Draft->value,
                'created_by' => $actor->getKey(),
            ])->save();

            return $transaction->refresh();
        });
    }

    /**
     * Update a draft cash/bank transaction.
     *
     * The status is re-read under a row lock rather than trusted from the
     * instance routing handed us. A check made before this transaction would
     * leave a window in which a concurrent post commits and the update then
     * rewrites a document that is already in the permanent record.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateDraft(
        CashBankTransaction $transaction,
        Company $company,
        CashBankTransactionType $type,
        array $data
    ): CashBankTransaction {
        return DB::transaction(function () use ($transaction, $company, $type, $data) {
            $fresh = CashBankTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            /*
             * Validate the pair that will exist *after* this update, not the pair
             * that exists now. Re-resolving only the fields the caller sent would
             * leave a transaction that passed validation on creation able to end
             * up with one ineligible side after a partial edit.
             */
            $accounts = $this->resolveAccounts($company, $type, array_merge([
                'source_account_id' => $fresh->source_account_id,
                'destination_account_id' => $fresh->destination_account_id,
            ], array_filter(
                $data,
                fn ($value, $field) => in_array($field, [
                    'source_account_id',
                    'destination_account_id',
                ], true) && $value !== null,
                ARRAY_FILTER_USE_BOTH
            )));

            $fresh->source_account_id = $accounts['source']->getKey();
            $fresh->destination_account_id = $accounts['destination']->getKey();

            /*
             * Phase 8: re-dating a draft may not target a closed period. See
             * JournalService::updateDraft for why this is checked on the date
             * only, and why it is checked at all.
             */
            if (array_key_exists('transaction_date', $data)) {
                $this->periods->assertDateNotClosed(
                    $company,
                    Carbon::parse($data['transaction_date']),
                    'transaction_date',
                );
            }

            foreach (['transaction_date', 'reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fresh->{$field} = $data[$field];
                }
            }

            if (array_key_exists('amount', $data)) {
                $fresh->amount = Money::ofTolerant($data['amount'])->toDatabase();
            }

            $fresh->save();

            return $fresh->refresh();
        });
    }

    /**
     * Delete a draft cash/bank transaction.
     *
     * Safe because a draft has no journal: nothing in the ledger refers to it.
     * The transaction number is still not returned to the sequence - see
     * DocumentNumberSequence, which allocates forward only.
     *
     * @throws ValidationException
     */
    public function deleteDraft(CashBankTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $fresh = CashBankTransaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            $fresh->delete();
        });
    }

    /**
     * Resolve and validate the account pair for a transaction type.
     *
     * @param  array<string, mixed>  $data
     * @return array{source: Account, destination: Account}
     *
     * @throws ValidationException
     */
    private function resolveAccounts(Company $company, CashBankTransactionType $type, array $data): array
    {
        $sourceId = (int) ($data['source_account_id'] ?? 0);
        $destinationId = (int) ($data['destination_account_id'] ?? 0);

        /*
         * Checked before either account is resolved, so that "you sent the same
         * account twice" is reported as the plainest possible thing rather than
         * after a pile of account-eligibility messages. Both accounts can be
         * valid and the transaction still impossible.
         */
        if ($sourceId !== 0 && $sourceId === $destinationId) {
            throw ValidationException::withMessages([
                'destination_account_id' => 'The source and destination accounts must be different. '
                    .'Moving money to the account it came from does not change anything.',
            ]);
        }

        if ($type->requiresBothAccountsCashBank()) {
            return [
                'source' => $this->accounts->cashBank($company, $sourceId, 'source_account_id'),
                'destination' => $this->accounts->cashBank($company, $destinationId, 'destination_account_id'),
            ];
        }

        $side = $type->cashBankSide();

        $resolved = [
            'source' => $side === 'source_account_id'
                ? $this->accounts->cashBank($company, $sourceId, 'source_account_id')
                : $this->accounts->offset($company, $sourceId, 'source_account_id'),

            'destination' => $side === 'destination_account_id'
                ? $this->accounts->cashBank($company, $destinationId, 'destination_account_id')
                : $this->accounts->offset($company, $destinationId, 'destination_account_id'),
        ];

        return $resolved;
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(CashBankTransaction $transaction): void
    {
        if (! $transaction->status->isDraft()) {
            throw ValidationException::withMessages([
                'transaction' => 'This cash/bank transaction is posted and cannot be changed. '
                    .'Record a reversing transaction instead.',
            ]);
        }
    }
}
