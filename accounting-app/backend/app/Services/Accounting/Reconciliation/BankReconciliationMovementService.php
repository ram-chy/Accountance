<?php

namespace App\Services\Accounting\Reconciliation;

use App\Enums\BankReconciliationStatus;
use App\Enums\JournalStatus;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\JournalLine;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationMovementService
{
    /**
     * @return array<string, mixed>
     */
    public function clearingTotals(BankReconciliation $reconciliation): array
    {
        $clearedIds = $this->clearedJournalLineIds($reconciliation);
        $all = $this->periodMovements($reconciliation);
        $cleared = $all->filter(fn ($line) => in_array($line->getKey(), $clearedIds, true));
        $uncleared = $all->filter(fn ($line) => ! in_array($line->getKey(), $clearedIds, true));

        return [
            'total' => $all->count(),
            'cleared_count' => $cleared->count(),
            'uncleared_count' => $uncleared->count(),
            'cleared' => [
                'debit' => $this->sumDebit($cleared),
                'credit' => $this->sumCredit($cleared),
            ],
            'uncleared' => [
                'debit' => $this->sumDebit($uncleared),
                'credit' => $this->sumCredit($uncleared),
            ],
        ];
    }

    /**
     * @return Collection<int, JournalLine>
     */
    public function eligibleMovements(BankReconciliation $reconciliation): Collection
    {
        $clearedIds = $this->clearedJournalLineIds($reconciliation);
        $alreadyReconciled = $this->alreadyReconciledLineIds($reconciliation);

        return $this->periodMovements($reconciliation)
            ->map(function (JournalLine $line) use ($clearedIds, $alreadyReconciled) {
                $line->setAttribute('cleared', in_array($line->getKey(), $clearedIds, true));
                $line->setAttribute('already_reconciled', in_array($line->getKey(), $alreadyReconciled, true));

                return $line;
            })
            ->filter(fn ($line) => ! $line->getAttribute('already_reconciled'))
            ->values();
    }

    public function addItem(BankReconciliation $reconciliation, int $journalLineId, ?string $notes = null): BankReconciliationItem
    {
        $this->assertMutable($reconciliation);

        return DB::transaction(function () use ($reconciliation, $journalLineId, $notes) {
            $fresh = BankReconciliation::query()->whereKey($reconciliation->getKey())->lockForUpdate()->firstOrFail();
            $this->assertMutable($fresh);
            $line = $this->loadEligibleLine($fresh, $journalLineId);
            $already = BankReconciliationItem::query()->where('bank_reconciliation_id', $fresh->getKey())->where('journal_line_id', $line->getKey())->exists();
            if ($already) {
                throw ValidationException::withMessages(['journal_line_id' => 'This movement is already cleared in this reconciliation.']);
            }
            $item = new BankReconciliationItem(['notes' => $notes]);
            $item->forceFill([
                'company_id' => $fresh->company_id,
                'bank_reconciliation_id' => $fresh->getKey(),
                'journal_id' => $line->journal_id,
                'journal_line_id' => $line->getKey(),
                'cleared_by' => auth()->id(),
                'cleared_at' => now(),
            ]);
            $item->save();
            $this->markInProgress($fresh);

            return $item->refresh();
        });
    }

    public function removeItem(BankReconciliation $reconciliation, BankReconciliationItem $item): void
    {
        $this->assertMutable($reconciliation);
        if ($item->bank_reconciliation_id !== $reconciliation->getKey()) {
            throw ValidationException::withMessages(['item_id' => 'The reconciliation item does not belong to this reconciliation.']);
        }
        DB::transaction(function () use ($reconciliation, $item) {
            $fresh = BankReconciliation::query()->whereKey($reconciliation->getKey())->lockForUpdate()->firstOrFail();
            $this->assertMutable($fresh);
            $item->delete();
            if ($fresh->items()->count() === 0 && $fresh->status->isInProgress()) {
                $fresh->forceFill(['status' => BankReconciliationStatus::Draft->value])->save();
            }
        });
    }

    private function loadEligibleLine(BankReconciliation $reconciliation, int $journalLineId): JournalLine
    {
        $line = JournalLine::query()->with('journal')->whereKey($journalLineId)->where('account_id', $reconciliation->account_id)->whereHas('journal', fn ($q) => $q->where('company_id', $reconciliation->company_id)->where('status', JournalStatus::Posted->value))->whereHas('journal', fn ($q) => $q->whereDate('journal_date', '<=', $reconciliation->to_date->toDateString()))->first();
        if ($line === null) {
            throw ValidationException::withMessages(['journal_line_id' => 'The selected movement is not eligible for this reconciliation.']);
        }
        $alreadyReconciled = BankReconciliationItem::query()->where('company_id', $reconciliation->company_id)->where('journal_line_id', $line->getKey())->whereHas('reconciliation', function ($q) use ($reconciliation) {
            $q->where('company_id', $reconciliation->company_id)->where('bank_account_id', $reconciliation->bank_account_id)->whereNotNull('completed_at')->whereNull('reopened_at')->whereKeyNot($reconciliation->getKey());
        })->exists();
        if ($alreadyReconciled) {
            throw ValidationException::withMessages(['journal_line_id' => 'This movement has already been reconciled in a previous reconciliation.']);
        }

        return $line;
    }

    /**
     * @return Collection<int, JournalLine>
     */
    private function periodMovements(BankReconciliation $reconciliation): Collection
    {
        /*
         | Company scope and status come through the journal, not the line.
         |
         | journal_lines has no company_id of its own - by design, so that
         | ownership has exactly one definition (journals.company_id) rather than
         | two that could disagree. Filtering the line table on a column it does
         | not have is an SQL error, not an empty result, so the predicate has to
         | go where the data is. This is the same rule LedgerService::postedLinesQuery
         | applies when it joins the two tables.
         |
         | Ordered by the journal's date rather than the line's: a line has no
         | date column of its own either, and a reconciliation is a statement
         | about a period, so the period order is the order that matters.
         |
         | The ordering is done in PHP because whereHas() compiles to an EXISTS
         | subquery rather than a join, so there is no journals table in the
         | FROM clause to sort by. The relation is eager loaded above, so this
         | reads no extra rows; it only reorders the set that was already
         | fetched. Journal id is the tie-break, because two lines on the same
         | day must still come back in a stable order.
         */
        return JournalLine::query()
            ->with('journal')
            ->where('account_id', $reconciliation->account_id)
            ->whereHas('journal', fn ($q) => $q
                ->where('company_id', $reconciliation->company_id)
                ->where('status', JournalStatus::Posted->value)
                ->whereDate('journal_date', '>=', $reconciliation->from_date->toDateString())
                ->whereDate('journal_date', '<=', $reconciliation->to_date->toDateString()))
            ->get()
            ->sortBy([
                fn (JournalLine $line) => $line->journal->journal_date->toDateString(),
                fn (JournalLine $line) => $line->journal->getKey(),
            ])
            ->values();
    }

    /**
     * @return int[]
     */
    private function clearedJournalLineIds(BankReconciliation $reconciliation): array
    {
        return BankReconciliationItem::query()->where('bank_reconciliation_id', $reconciliation->getKey())->pluck('journal_line_id')->toArray();
    }

    /**
     * @return int[]
     */
    private function alreadyReconciledLineIds(BankReconciliation $reconciliation): array
    {
        return BankReconciliationItem::query()->where('company_id', $reconciliation->company_id)->whereHas('reconciliation', function ($q) use ($reconciliation) {
            $q->where('company_id', $reconciliation->company_id)->where('bank_account_id', $reconciliation->bank_account_id)->whereNotNull('completed_at')->whereNull('reopened_at')->whereKeyNot($reconciliation->getKey());
        })->pluck('journal_line_id')->toArray();
    }

    private function sumDebit(Collection $lines): Money
    {
        $total = Money::zero();
        foreach ($lines as $line) {
            $total = $total->plus($line->debitAmount());
        }

        return $total;
    }

    private function sumCredit(Collection $lines): Money
    {
        $total = Money::zero();
        foreach ($lines as $line) {
            $total = $total->plus($line->creditAmount());
        }

        return $total;
    }

    private function assertMutable(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->status->isReconciled()) {
            throw ValidationException::withMessages(['status' => 'A reconciled reconciliation cannot be modified.']);
        }
    }

    private function markInProgress(BankReconciliation $reconciliation): void
    {
        if (! $reconciliation->status->isInProgress()) {
            $reconciliation->forceFill(['status' => BankReconciliationStatus::InProgress->value])->save();
        }
    }
}
