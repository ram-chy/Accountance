<?php

namespace App\Services\Accounting\Reconciliation;

use App\Enums\BankReconciliationStatus;
use App\Models\BankReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationReopenService
{
    public function reopen(BankReconciliation $reconciliation): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation) {
            $fresh = BankReconciliation::query()
                ->whereKey($reconciliation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $fresh->status->isReconciled()) {
                throw ValidationException::withMessages([
                    'status' => 'Only a reconciled reconciliation can be reopened.',
                ]);
            }

            $fresh->forceFill([
                'status' => BankReconciliationStatus::InProgress->value,
                'reopened_by' => auth()->id(),
                'reopened_at' => now(),
            ])->save();

            return $fresh->refresh();
        });
    }
}
