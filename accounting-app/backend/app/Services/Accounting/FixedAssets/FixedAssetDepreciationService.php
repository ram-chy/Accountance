<?php

namespace App\Services\Accounting\FixedAssets;

use App\Enums\FixedAssetStatus;
use App\Enums\JournalSource;
use App\Exceptions\ConflictException;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
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
 * Posting one period's depreciation for one asset.
 *
 * THE ENTRY
 *
 *   Dr Depreciation Expense      the charge for the period
 *       Cr Accumulated Depreciation  the same amount
 *
 * Two lines, one amount, and the amount comes from the calculator rather than from
 * the request. Nothing about the entry is negotiable from outside - not the accounts,
 * not the amount, not the period - which is why this service takes an asset and
 * nothing else.
 *
 * THE ORDER RULE: EARLIEST UNPOSTED PERIOD ONLY
 *
 * Period 5 cannot be charged before period 4. That rule does most of the work in this
 * class:
 *
 *   - It makes "how much has been written down so far" a simple sum, with no gaps for
 *     the calculation to reason about.
 *   - It makes the final-period rounding correction land in the right period. The
 *     correction is defined as "whatever remains", and "whatever remains" is only
 *     meaningful when nothing is missing beneath it.
 *   - It makes a mistake recoverable. If period 4 was charged at the wrong amount, the
 *     fix is a reversing entry for period 4, and period 5 can then be charged
 *     correctly - whereas an out-of-order schedule leaves an asset whose periods do
 *     not add up to its life, with nothing to say which one is wrong.
 *
 * IT IS ENFORCED THREE TIMES, ON PURPOSE
 *
 *   1. Here, against the committed state, under the asset's row lock.
 *   2. By a unique constraint on (fixed_asset_id, period_number) - which is what
 *      actually stops two concurrent runs of the SAME period.
 *   3. By a second unique constraint on (fixed_asset_id, period_start_date), which
 *      catches a row that arrived with a period number off by one.
 *
 * (1) alone would not survive concurrency: two transactions can both read the same
 * highest posted period and both conclude that the next one is free. Only the index
 * is consulted after the other has committed.
 */
class FixedAssetDepreciationService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
        private readonly FixedAssetDepreciationCalculator $calculator,
    ) {}

    /**
     * Post the next period's depreciation for an asset.
     *
     * @throws ConflictException when the asset cannot be depreciated
     * @throws ValidationException
     */
    public function depreciate(FixedAsset $asset, User $actor, ?Carbon $asOf = null): FixedAssetDepreciation
    {
        return DB::transaction(function () use ($asset, $actor, $asOf) {
            /*
             * The asset row is locked before anything is read or decided. This is the
             * lock that makes the order rule true rather than merely intended: a
             * concurrent run of the same asset blocks here until this transaction has
             * committed or rolled back.
             */
            $fresh = $this->lock($asset);

            /*
             * Asked of the ENUM, not derived from the period count. A disposed asset
             * has completed every period it had, so "all periods charged" is true of it
             * too - which is precisely the case a count-based check would get wrong and
             * would go on charging depreciation for an asset the company no longer
             * owns.
             */
            if (! $fresh->status->isDepreciable()) {
                throw $this->notDepreciable($fresh);
            }

            /*
             * A zero depreciable base - salvage equal to cost - produces no periods at
             * all, and the calculator says so by returning a zero amount. That is not
             * an error: it is an asset whose entire cost is recovered, and there is
             * nothing to charge. It is reported rather than posted because
             * fixed_asset_deprecations.amount is CHECK-constrained to be positive and
             * JournalService refuses a zero-amount line anyway.
             */
            if ($fresh->depreciableBaseAmount()->isZero()) {
                throw ValidationException::withMessages([
                    'fixed_asset' => sprintf(
                        'Asset [%s] has a salvage value equal to its cost, so there is nothing to depreciate.',
                        $fresh->asset_number
                    ),
                ]);
            }

            $posted = $this->postedPeriods($fresh);

            /*
             * The posted periods must run 1, 2, 3 ... with nothing absent, or
             * "earliest unposted period" has no single answer: a set [1, 2, 4] would
             * let this method post 5, leaving period 3 uncharged forever and the
             * schedule's amounts no longer summing to the depreciable base.
             *
             * This should be unreachable through the application - the unique
             * constraints and the row lock prevent the writes that could create it -
             * but it is the invariant the whole class depends on, so it is asserted
             * rather than assumed. A corrupted or hand-edited table is exactly the
             * case a silent miscalculation would hide.
             */
            $missing = $this->calculator->firstMissingPeriod($posted);

            if ($missing !== null) {
                throw new ConflictException(
                    message: 'This asset\'s depreciation schedule has a gap, so later periods cannot be charged.',
                    errors: ['fixed_asset' => [sprintf(
                        'Asset [%s] is missing depreciation for period %d. The schedule must be '
                        .'corrected before any later period is charged.',
                        $fresh->asset_number,
                        $missing
                    )]],
                );
            }

            $period = $this->calculator->period(
                $fresh,
                $this->calculator->nextPeriodNumber($fresh, $this->calculator->highestPeriodNumber($posted)),
                $this->calculator->sumOf($posted)
            );

            /*
             * Has the period actually elapsed?
             *
             * A monthly run asks this once per asset as of today, so a schedule is
             * built period by period and stops at the first that has not finished.
             * Posting early would charge for a month the company has not yet owned the
             * asset for, and - because the posting date is the period's END date -
             * would also mean dating a journal into the future, which
             * AccountingPeriodService would refuse for a period that does not exist.
             */
            if (! $this->calculator->periodHasElapsed($fresh, $period->number, $asOf ?? Carbon::today())) {
                throw ValidationException::withMessages([
                    'fixed_asset' => sprintf(
                        'Period %d of asset [%s] runs to %s and has not ended yet, so it cannot be charged.',
                        $period->number,
                        $fresh->asset_number,
                        $period->endDate->toDateString()
                    ),
                ]);
            }

            /*
             * Both accounts re-resolved at posting time, for the reason
             * FixedAssetService::capitalise() gives: a debit-normal accumulated
             * depreciation account would post this entry perfectly and then make the
             * asset GREATER on the balance sheet rather than smaller, and the trial
             * balance would still foot. The check is the only thing standing between
             * that and a balance sheet that quietly inflates.
             */
            $expenseAccount = $this->accounts->depreciationExpense(
                $fresh->company,
                $fresh->depreciation_expense_account_id
            );

            $accumulatedAccount = $this->accounts->accumulatedDepreciation(
                $fresh->company,
                $fresh->accumulated_depreciation_account_id
            );

            $amount = $period->amount;

            if (! $amount->isPositive()) {
                throw ValidationException::withMessages([
                    'fixed_asset' => sprintf(
                        'Asset [%s] has no remaining amount to depreciate.',
                        $fresh->asset_number
                    ),
                ]);
            }

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $period->postingDate()->toDateString(),
                    'description' => sprintf(
                        'Depreciation of fixed asset %s, period %d',
                        $fresh->asset_number,
                        $period->number
                    ),
                    'reference' => $fresh->asset_number,
                    'source_type' => JournalSource::FixedAssetDepreciation->value,
                    'lines' => [
                        [
                            'account_id' => $expenseAccount->getKey(),
                            'description' => 'Depreciation expense',
                            'debit' => $amount->toDatabase(),
                            'credit' => '0',
                        ],
                        [
                            'account_id' => $accumulatedAccount->getKey(),
                            'description' => 'Accumulated depreciation',
                            'debit' => '0',
                            'credit' => $amount->toDatabase(),
                        ],
                    ],
                ]
            );

            /*
             * forceFill, not create(), because this model declares an empty fillable
             * list - every column on it is a server decision. Mass assignment would
             * discard the whole array rather than fail loudly, so the insert would
             * fail on the first NOT NULL column and report a database error for what
             * is really a programming mistake here.
             *
             * Written BEFORE the journal is posted, even though it records a posted
             * charge, because the journal needs this row's id as its source_id. The
             * two are committed together or not at all, so by the time anyone can
             * observe the row, the journal it names is posted.
             */
            $depreciation = new FixedAssetDepreciation;

            $depreciation->forceFill([
                'company_id' => $fresh->company_id,
                'fixed_asset_id' => $fresh->getKey(),
                'period_number' => $period->number,
                'period_start_date' => $period->startDate->toDateString(),
                'period_end_date' => $period->endDate->toDateString(),
                'amount' => $amount->toDatabase(),
                'journal_id' => $journal->getKey(),
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
                'created_by' => $actor->getKey(),
            ]);

            try {
                $depreciation->save();
            } catch (QueryException $e) {
                /*
                 * A unique constraint fired.
                 *
                 * This is the third line of defence described in the class docblock,
                 * and the one that is genuinely load-bearing under concurrency: the
                 * asset row lock above means a competing run of the SAME asset is
                 * serialised, so the realistic case here is a row that already exists
                 * from a retry of a request whose earlier attempt committed the
                 * journal and then failed before this insert.
                 *
                 * In that case the transaction rolls back, taking the just-created
                 * journal with it, so no double charge is possible - and the conflict
                 * is reported to the caller instead.
                 */
                if (in_array($e->getCode(), ['23000', '23505'], true)) {
                    throw new ConflictException(
                        message: 'This depreciation period has already been charged.',
                        errors: ['fixed_asset' => [sprintf(
                            'Period %d of asset [%s] has already been charged.',
                            $period->number,
                            $fresh->asset_number
                        )]],
                    );
                }

                throw $e;
            }

            /*
             * source_id names THIS depreciation row, not the asset.
             *
             * The enum's own contract is that source_id resolves against the table the
             * source_type names - fixed_asset_depreciations here - and the source index
             * is only useful if that holds. Pointing it at the asset would make every
             * depreciation journal for an asset resolve to the same row, so "which
             * entry wrote this?" would have no answer.
             *
             * The row had to exist first, which is why the journal was created as a
             * draft above and is only posted now - JournalService is the only writer
             * of journals, and updateDraft is its supported way to set this column
             * while the entry is still editable.
             */
            $this->journals->updateDraft(
                $journal,
                $fresh->company,
                ['source_id' => $depreciation->getKey()]
            );

            $this->posting->post($journal->refresh(), $actor, 'journal_date');

            /*
             * Whether this was the final period, asked from the calculator's own
             * verdict rather than recomputed here - the DepreciationPeriod carries
             * isFinal precisely so that the flag which decided the AMOUNT is the same
             * flag that decides the STATUS.
             */
            if ($period->isFinal) {
                $fresh->forceFill([
                    'status' => FixedAssetStatus::FullyDepreciated->value,
                    'fully_depreciated_at' => now(),
                    'updated_by' => $actor->getKey(),
                ])->save();
            }

            return $depreciation->refresh();
        });
    }

    /**
     * The asset's remaining schedule, for the schedule endpoint.
     *
     * The same calculator and the same posted rows the depreciation path uses, so what
     * a user is shown is what the next charge will actually take - there is no second
     * implementation of the arithmetic for display. This is a read and takes no lock:
     * the schedule is advisory, and the posting path re-derives it under the asset lock
     * before it writes anything.
     *
     * @return array<int, DepreciationPeriod>
     */
    public function schedule(FixedAsset $asset): array
    {
        return $this->calculator->schedule($asset, $this->postedPeriods($asset));
    }

    /**
     * An asset's posted periods, as the calculator wants them.
     *
     * Locked for reading consistency with the FOR UPDATE already held on the asset:
     * the rows are read inside the same transaction, so any competing writer of these
     * rows is either already blocked on the asset lock or has committed and is
     * therefore visible here.
     */
    private function postedPeriods(FixedAsset $asset): array
    {
        return $this->calculator->postedPeriods(
            FixedAssetDepreciation::query()
                ->where('fixed_asset_id', $asset->getKey())
                ->orderBy('period_number')
                ->get()
                ->map(fn (FixedAssetDepreciation $row) => new FixedAssetDepreciationSummary(
                    number: (int) $row->period_number,
                    amount: Money::of($row->amount),
                ))
                ->all()
        );
    }

    /**
     * Why this asset cannot be depreciated.
     *
     * Each state gets its own message because the remedy differs in every case, and a
     * single generic refusal would leave the user guessing which one they are in:
     * capitalise it first, accept that it is finished, or accept that it has gone.
     */
    private function notDepreciable(FixedAsset $asset): ConflictException
    {
        $message = match (true) {
            $asset->status->isDraft() => sprintf(
                'Asset [%s] is still a draft and has no cost in the ledger. Capitalise it before depreciating it.',
                $asset->asset_number
            ),
            $asset->status->isDisposed() => sprintf(
                'Asset [%s] was disposed of on %s and cannot be depreciated further.',
                $asset->asset_number,
                $asset->disposed_at?->toDateString() ?? 'an earlier date'
            ),
            default => sprintf(
                'Asset [%s] is fully depreciated and has no remaining amount to charge.',
                $asset->asset_number
            ),
        };

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
