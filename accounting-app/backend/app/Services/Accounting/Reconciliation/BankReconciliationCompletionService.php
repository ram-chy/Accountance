<?php

namespace App\Services\Accounting\Reconciliation;

use App\Enums\BankReconciliationStatus;
use App\Models\BankReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationCompletionService
{
    public function __construct(
        private readonly BankReconciliationCalculator $calculator,
    ) {}

    public function complete(BankReconciliation $reconciliation): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliation) {
            $fresh = BankReconciliation::query()
                ->whereKey($reconciliation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isReconciled()) {
                throw ValidationException::withMessages([
                    'status' => 'This reconciliation has already been completed.',
                ]);
            }

            if (! $this->calculator->canComplete($fresh)) {
                $diff = $this->calculator->summary($fresh)['difference'];

                throw ValidationException::withMessages([
                    'difference' => 'The reconciliation cannot be completed because the difference is '.$diff->toString().'.',
                ]);
            }

            $fresh->forceFill([
                'status' => BankReconciliationStatus::Reconciled->value,
                'completed_by' => auth()->id(),
                'completed_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
            ])->save();

            return $fresh->refresh();
        });
    }
}
