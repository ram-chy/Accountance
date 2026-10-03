<?php

namespace App\Services\Accounting\FixedAssets;

use App\Enums\FixedAssetStatus;
use App\Enums\JournalSource;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetDisposal;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removing an asset from the register and from the balance sheet.
 *
 * THE ENTRY
 *
 *   Dr Accumulated Depreciation   the TOTAL accumulated depreciation
 *   Dr Loss on Disposal           only if the sale fell short of book value
 *   Dr Proceeds account           what was received
 *       Cr Fixed Asset            the ORIGINAL COST
 *       Cr Gain on Disposal       only if the sale exceeded book value
 *
 * Five lines at most, and the three shapes reduce to one question: are the proceeds
 * above, below or equal to the carrying value?
 *
 *   above    Cr Gain        (and no loss line)
 *   below    Dr Loss        (and no gain line)
 *   equal    neither - a two-sided removal with no result
 *
 * TWO AMOUNTS THAT ARE NOT THE SAME NUMBER, and which are easy to confuse:
 *
 *   carrying value       original cost less accumulated depreciation - what the
 *                        asset is worth, and what the gain or loss is measured
 *                        against. This is the figure STORED on the disposal.
 *
 *   accumulated          original cost less carrying value - the total write-down
 *                        made against this asset, and what the entry DEBITS
 *                        accumulated depreciation for.
 *
 * The credit to the fixed asset account is the original cost, because that is the
 * entire balance on that account for this asset and it is what leaves. Getting the
 * accumulated figure wrong therefore does not merely produce a different result -
 * it produces an entry that does not balance, and the debit side comes up short by
 * the difference. Verified across gain, loss, even and fully depreciated cases
 * before the arithmetic was written down.
 *
 * THE PROCEEDS MUST BE ON THE DEBIT SIDE
 *
 * Leaving that line out does not produce a simpler entry, it produces an unbalanced
 * one: with it removed, the debits are accumulated + loss and the credits are cost +
 * gain, and those are equal only in the single case where the company received
 * nothing at all.
 *
 * THE FIGURES ARE FROZEN, AND THAT IS THE POINT
 *
 * carrying_value_at_disposal is what the asset was worth ON THE DISPOSAL DATE, which
 * is a historical fact and is stored rather than derived - the same reasoning
 * FixedAsset uses for the opposite decision, where deriving is right because the
 * figure keeps changing.
 *
 * Deriving it here would give the wrong answer as soon as anything else was charged
 * to the asset afterwards, and the whole point of a disposal note is that it cannot
 * change after the fact.
 *
 * SALVAGE IS NOT THE COMPARISON
 *
 * The result is measured against the CARRYING value, not against salvage value, and
 * the distinction matters in the most common case there is. Salvage is what the
 * company expects to recover at the end of the asset's life; a vehicle sold early for
 * less than its written-down value produces a loss even though it fetched more than
 * its salvage estimate. Comparing against salvage would report that as a gain.
 *
 * GAIN AND LOSS NEED NOT BE CONFIGURED TOGETHER
 *
 * A company that has never sold anything for profit has no gain-on-disposal account
 * and does not need one. The resolver asks for whichever of the two the outcome
 * actually requires, and refuses - with the missing field named - if the outcome needs
 * an account the category does not have.
 */
class FixedAssetDisposalService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
    ) {}

    /**
     * Remove an asset from the register.
     *
     * @param  array<string, mixed>  $data  disposal_date, proceeds, reason,
     *                                      proceeds_account_id
     *
     * @throws ConflictException when the asset cannot be disposed
     * @throws ValidationException
     */
    public function dispose(FixedAsset $asset, User $actor, array $data): FixedAssetDisposal
    {
        $proceeds = Money::of((string) ($data['proceeds'] ?? 0));

        if ($proceeds->isNegative()) {
            throw ValidationException::withMessages([
                'proceeds' => 'Disposal proceeds cannot be negative. Enter zero for an asset '
                    .'written off or scrapped for no money.',
            ]);
        }

        return DB::transaction(function () use ($asset, $actor, $data, $proceeds) {
            $fresh = $this->lock($asset);

            /*
             * Asked of the ENUM, and the enum includes FULLY_DEPRECIATED on purpose.
             * A worn-out vehicle with no book value left is still owned and still has a
             * cost to remove from the ledger - and selling one is the single most
             * common disposal there is. A check that refused it would leave a company
             * unable to sell anything it had finished depreciating.
             */
            if (! $fresh->status->isDisposable()) {
                throw $this->notDisposable($fresh);
            }

            /*
             * A disposal cannot be backdated to before depreciation already posted.
             *
             * Carrying value below is the sum of every posted depreciation row. That
             * is the value AS AT the disposal date only if no charge has been posted
             * for a period ending after it; otherwise the sum describes a later moment
             * and the entry would freeze a carrying value that never existed on the
             * date it claims. A disposal dated 31 March with an April charge already
             * in the ledger is the case, and it is refused rather than quietly valued
             * at April's figure under a March date.
             *
             * Not "depreciation must be caught up to the disposal date": periods that
             * have elapsed but not been charged are the user's to book or not, and the
             * difference simply lands in the gain or loss. What is refused is a date
             * that contradicts a fact already written.
             */
            $lastPostedPeriodEnd = FixedAssetDepreciation::query()
                ->where('fixed_asset_id', $fresh->getKey())
                ->max('period_end_date');

            if ($lastPostedPeriodEnd !== null
                && Carbon::parse($lastPostedPeriodEnd)->greaterThan(Carbon::parse($data['disposal_date']))) {
                throw ValidationException::withMessages([
                    'disposal_date' => sprintf(
                        'Asset [%s] has depreciation posted through %s, so it cannot be disposed of on '
                        .'the earlier date %s.',
                        $fresh->asset_number,
                        Carbon::parse($lastPostedPeriodEnd)->toDateString(),
                        Carbon::parse($data['disposal_date'])->toDateString()
                    ),
                ]);
            }

            /*
             * Carrying value, derived from the posted depreciation rows and measured
             * BEFORE anything is written - so the amount the journal debits accumulated
             * depreciation for is the amount the register will then show, with nothing
             * derived twice and possibly disagreeing.
             */
            $carrying = $fresh->carryingAmount();

            /*
             * The result, and it is a comparison of two frozen figures rather than a
             * calculation that could drift: proceeds against the carrying value at
             * this instant. Stored as two non-negative columns rather than one signed
             * one, because the entry needs to know WHICH of the two it is dealing with
             * and the CHECK constraint makes "not both" a database fact.
             */
            $result = $proceeds->minus($carrying);

            $gain = $result->isPositive() ? $result : Money::zero();
            $loss = $result->isNegative() ? $result->negate() : Money::zero();

            $accounts = $this->resolveAccounts($fresh, $data, $gain, $loss);

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $data['disposal_date'],
                    'description' => sprintf(
                        'Disposal of fixed asset %s%s',
                        $fresh->asset_number,
                        isset($data['reason']) && $data['reason'] !== ''
                            ? ' - '.$data['reason']
                            : ''
                    ),
                    'reference' => $fresh->asset_number,
                    'source_type' => JournalSource::FixedAssetDisposal->value,
                    'lines' => $this->journalLines($fresh, $accounts, $proceeds, $carrying, $gain, $loss),
                ]
            );

            /*
             * The disposal row and the asset's status, written in the same transaction
             * as the journal. The unique constraint on fixed_asset_disposals
             * .fixed_asset_id is what actually prevents a second disposal: the status
             * check above can be raced, but the index cannot.
             *
             * Before the post rather than after, because the journal's source_id is
             * this row's id: source_type names fixed_asset_disposals, so source_id has
             * to resolve against it. The whole transaction commits or rolls back
             * together, so an observer never sees the row without its posted journal.
             */
            $disposal = new FixedAssetDisposal;

            $disposal->forceFill([
                'company_id' => $fresh->company_id,
                'fixed_asset_id' => $fresh->getKey(),
                'disposal_date' => $data['disposal_date'],
                'reason' => $data['reason'] ?? null,
                'proceeds' => $proceeds->toDatabase(),
                'carrying_value_at_disposal' => $carrying->toDatabase(),
                'gain' => $gain->toDatabase(),
                'loss' => $loss->toDatabase(),
                'proceeds_account_id' => $accounts['proceeds']->getKey(),
                'journal_id' => $journal->getKey(),
                'disposed_by' => $actor->getKey(),
                'posted_at' => now(),
                'created_by' => $actor->getKey(),
            ]);

            try {
                $disposal->save();
            } catch (QueryException $e) {
                if (in_array($e->getCode(), ['23000', '23505'], true)) {
                    throw new ConflictException(
                        message: 'This fixed asset has already been disposed of.',
                        errors: ['fixed_asset' => [sprintf(
                            'Asset [%s] has already been disposed of.',
                            $fresh->asset_number
                        )]],
                    );
                }

                throw $e;
            }

            // source_id is the disposal row, matching source_type - see the
            // depreciation service for the full reasoning.
            $this->journals->updateDraft(
                $journal,
                $fresh->company,
                ['source_id' => $disposal->getKey()]
            );

            $this->posting->post($journal->refresh(), $actor, 'journal_date');

            /*
             * Status, disposal pointers and audit columns in one statement. The schema
             * CHECK on (status <> 'DISPOSED') or disposed_at is not null is satisfied
             * by this single write, so there is no instant at which the asset claims to
             * be disposed without a date.
             */
            $fresh->forceFill([
                'status' => FixedAssetStatus::Disposed->value,
                'disposed_at' => now(),
                'disposed_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            return $disposal->refresh();
        });
    }

    /**
     * Every account the entry needs, each resolved for the role it plays.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, Account>
     *
     * @throws ValidationException
     */
    private function resolveAccounts(FixedAsset $asset, array $data, Money $gain, Money $loss): array
    {
        $resolved = [
            // The cost being removed. Not yet validated for type beyond being an asset
            // account, because a mismatch here is a category configuration error that
            // would have surfaced at capitalisation.
            'asset' => $this->accounts->fixedAsset($asset->company, $asset->asset_account_id),

            // The write-down being reversed. Debited here rather than credited, which
            // is why the CREDIT-normal check matters on this path and not only on the
            // depreciation one.
            'accumulated' => $this->accounts->accumulatedDepreciation(
                $asset->company,
                $asset->accumulated_depreciation_account_id
            ),

            // Where the money went. An ASSET, whether a bank account or the company's
            // receivables.
            'proceeds' => $this->accounts->proceedsAccount(
                $asset->company,
                (int) $data['proceeds_account_id']
            ),
        ];

        /*
         * Resolved only when the outcome actually needs them, which is what makes a
         * half-configured category workable: a company with a loss account and no gain
         * account can sell things at a loss, and only a profitable sale stops it.
         *
         * The error names the missing field rather than saying "configuration
         * problem", because the user's remedy is obvious once they know which account
         * the entry wanted.
         */
        if ($gain->isPositive()) {
            if ($asset->gain_on_disposal_account_id === null) {
                throw ValidationException::withMessages([
                    'gain_on_disposal_account_id' => sprintf(
                        'This disposal makes a gain of %s, but the category for asset [%s] has no '
                        .'gain-on-disposal account configured.',
                        (string) $gain,
                        $asset->asset_number
                    ),
                ]);
            }

            $resolved['gain'] = $this->accounts->gainOnDisposal(
                $asset->company,
                $asset->gain_on_disposal_account_id
            );
        }

        if ($loss->isPositive()) {
            if ($asset->loss_on_disposal_account_id === null) {
                throw ValidationException::withMessages([
                    'loss_on_disposal_account_id' => sprintf(
                        'This disposal makes a loss of %s, but the category for asset [%s] has no '
                        .'loss-on-disposal account configured.',
                        (string) $loss,
                        $asset->asset_number
                    ),
                ]);
            }

            $resolved['loss'] = $this->accounts->lossOnDisposal(
                $asset->company,
                $asset->loss_on_disposal_account_id
            );
        }

        return $resolved;
    }

    /**
     * Build the entry's lines.
     *
     * The accumulated depreciation leg is debited for the TOTAL ACCUMULATED figure -
     * original cost less carrying value - and not for the carrying value. Those are
     * different numbers and confusing them produces an entry that does not balance:
     *
     *   cost 1000, accumulated 400, carrying 600, proceeds 600, no gain
     *     correct     Dr Accumulated Dep  400, Dr Proceeds 600,  Cr Fixed Asset 1000
     *     if carrying Dr Accumulated Dep  600, Dr Proceeds 600,  Cr Fixed Asset 1000
     *
     * The second is short by 200 on the debit side. The credit to the fixed asset
     * account is the ORIGINAL COST - that is the whole balance on that account, and
     * it is what leaves - so whatever the other side is short by is what the entry
     * fails to balance by.
     *
     * The result lines are CONDITIONAL rather than zero-amount. A line for a loss
     * that did not occur would be rejected by JournalService for having a zero amount,
     * and a zero line in an entry is misleading anyway - it suggests the loss was
     * considered and found to be nil, which is a different statement from "no loss
     * account is involved in this entry".
     *
     * Note what needs no special case: a fully depreciated asset sold for nothing.
     * Its accumulated depreciation equals its cost and its carrying value is zero, so
     * the entry is two lines - Dr Accumulated Depreciation, Cr Fixed Asset - and it
     * balances exactly. Scrapping a written-off asset is a real event that moves real
     * balances, and this is the entry for it.
     *
     * @param  array<string, Account>  $accounts
     * @return array<int, array<string, mixed>>
     */
    private function journalLines(
        FixedAsset $asset,
        array $accounts,
        Money $proceeds,
        Money $carrying,
        Money $gain,
        Money $loss
    ): array {
        $accumulated = $asset->originalCostAmount()->minus($carrying);

        $lines = [];

        /*
         * Conditional, like the gain, loss and proceeds below, and for the same reason:
         * JournalService refuses a line that is zero on both sides, so an unconditional
         * line here would make one whole class of disposal impossible.
         *
         * The case is an asset capitalised moments ago and then sold or written off
         * before any depreciation ran. Nothing has been written down, so the
         * accumulated leg is genuinely zero and the entry is Dr Loss / Dr Proceeds /
         * Cr Fixed Asset - which still balances, because carrying value equals cost
         * and proceeds plus loss must then equal cost.
         *
         * Emitting "Dr Accumulated Depreciation 0.00" would be notationally true and
         * practically wrong: it would be refused outright, so the owner could not
         * sell a brand-new asset.
         */
        if ($accumulated->isPositive()) {
            $lines[] = [
                'account_id' => $accounts['accumulated']->getKey(),
                'description' => 'Accumulated depreciation',
                'debit' => $accumulated->toDatabase(),
                'credit' => '0',
            ];
        }

        if ($loss->isPositive()) {
            $lines[] = [
                'account_id' => $accounts['loss']->getKey(),
                'description' => 'Loss on disposal',
                'debit' => $loss->toDatabase(),
                'credit' => '0',
            ];
        }

        if ($proceeds->isPositive()) {
            $lines[] = [
                'account_id' => $accounts['proceeds']->getKey(),
                'description' => 'Disposal proceeds',
                'debit' => $proceeds->toDatabase(),
                'credit' => '0',
            ];
        }

        $lines[] = [
            'account_id' => $accounts['asset']->getKey(),
            'description' => 'Fixed asset',
            'debit' => '0',
            'credit' => $asset->originalCostAmount()->toDatabase(),
        ];

        if ($gain->isPositive()) {
            $lines[] = [
                'account_id' => $accounts['gain']->getKey(),
                'description' => 'Gain on disposal',
                'debit' => '0',
                'credit' => $gain->toDatabase(),
            ];
        }

        return $lines;
    }

    private function notDisposable(FixedAsset $asset): ConflictException
    {
        $message = $asset->status->isDraft()
            ? sprintf(
                'Asset [%s] is still a draft and has no cost in the ledger, so there is nothing to dispose of. '
                .'Delete it if it was recorded in error.',
                $asset->asset_number
            )
            : sprintf(
                'Asset [%s] was already disposed of on %s.',
                $asset->asset_number,
                $asset->disposed_at?->toDateString() ?? 'an earlier date'
            );

        return new ConflictException(
            message: $message,
            errors: ['status' => [$message]],
        );
    }

    private function lock(FixedAsset $asset): FixedAsset
    {
        return FixedAsset::query()
            ->whereKey($asset->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
