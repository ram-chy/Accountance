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
        $line = JournalLine::query()->with('journal')->where('company_id', $reconciliation->company_id)->whereKey($journalLineId)->where('account_id', $reconciliation->account_id)->whereHas('journal', fn ($q) => $q->where('status', JournalStatus::Posted->value))->whereHas('journal', fn ($q) => $q->whereDate('date', '<=', $reconciliation->to_date->toDateString()))->first();
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
        return JournalLine::query()->with('journal')->where('company_id', $reconciliation->company_id)->where('account_id', $reconciliation->account_id)->whereHas('journal', fn ($q) => $q->where('status', JournalStatus::Posted->value))->whereHas('journal', fn ($q) => $q->whereDate('date', '>=', $reconciliation->from_date->toDateString()))->whereHas('journal', fn ($q) => $q->whereDate('date', '<=', $reconciliation->to_date->toDateString()))->orderBy('date')->orderBy('id')->get();
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
