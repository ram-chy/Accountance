<?php

namespace App\Services\Accounting\Reconciliation;

use App\Enums\BankReconciliationStatus;
use App\Enums\CashBankKind;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    public function __construct(
        private readonly BankReconciliationCalculator $calculator,
        private readonly BankReconciliationMovementService $movements,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, User $actor, array $data): BankReconciliation
    {
        $this->validateCreation($company, $data);

        return DB::transaction(function () use ($company, $actor, $data) {
            $bankAccount = BankAccount::query()
                ->with('account')
                ->where('company_id', $company->getKey())
                ->whereKey($data['bank_account_id'])
                ->firstOrFail();

            $account = $bankAccount->account;

            if ($account === null) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'The selected bank account is not linked to an account.',
                ]);
            }

            if ($account->company_id !== $company->getKey()) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'The selected bank account does not belong to this company.',
                ]);
            }

            if ($account->cash_bank_kind !== CashBankKind::Bank) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Only bank accounts can be reconciled.',
                ]);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'The selected bank account is inactive.',
                ]);
            }

            if (! $bankAccount->is_active) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'The selected bank account metadata is inactive.',
                ]);
            }

            $from = Carbon::parse($data['from_date']);
            $to = Carbon::parse($data['to_date']);

            if ($from->gt($to)) {
                throw ValidationException::withMessages([
                    'to_date' => 'The to date must be on or after the from date.',
                ]);
            }

            $statementOpening = Money::ofTolerant($data['statement_opening_balance']);
            $statementClosing = Money::ofTolerant($data['statement_closing_balance']);

            $reconciliation = new BankReconciliation([
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'statement_opening_balance' => $statementOpening->toDatabase(),
                'statement_closing_balance' => $statementClosing->toDatabase(),
                'notes' => $data['notes'] ?? null,
            ]);

            $reconciliation->forceFill([
                'company_id' => $company->getKey(),
                'bank_account_id' => $bankAccount->getKey(),
                'account_id' => $account->getKey(),
                'status' => BankReconciliationStatus::Draft->value,
                'created_by' => $actor->getKey(),
            ]);

            $reconciliation->save();

            return $reconciliation->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BankReconciliation $reconciliation, array $data): BankReconciliation
    {
        $this->assertEditable($reconciliation);

        return DB::transaction(function () use ($reconciliation, $data) {
            $fresh = BankReconciliation::query()
                ->whereKey($reconciliation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($fresh);

            if (array_key_exists('statement_opening_balance', $data)) {
                $fresh->statement_opening_balance = Money::ofTolerant($data['statement_opening_balance'])->toDatabase();
            }

            if (array_key_exists('statement_closing_balance', $data)) {
                $fresh->statement_closing_balance = Money::ofTolerant($data['statement_closing_balance'])->toDatabase();
            }

            if (array_key_exists('from_date', $data)) {
                $fresh->from_date = Carbon::parse($data['from_date'])->toDateString();
            }

            if (array_key_exists('to_date', $data)) {
                $fresh->to_date = Carbon::parse($data['to_date'])->toDateString();
            }

            if (array_key_exists('notes', $data)) {
                $fresh->notes = $data['notes'];
            }

            if ($fresh->from_date && $fresh->to_date && $fresh->from_date->gt($fresh->to_date)) {
                throw ValidationException::withMessages([
                    'to_date' => 'The to date must be on or after the from date.',
                ]);
            }

            if ($fresh->items()->count() > 0) {
                if (array_key_exists('bank_account_id', $data) && (int) $data['bank_account_id'] !== $fresh->bank_account_id) {
                    throw ValidationException::withMessages([
                        'bank_account_id' => 'The bank account cannot be changed once reconciliation items exist.',
                    ]);
                }
            }

            $fresh->save();

            return $fresh->refresh();
        });
    }

    public function deleteDraft(BankReconciliation $reconciliation): void
    {
        if (! $reconciliation->status->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft reconciliations can be deleted.',
            ]);
        }

        $reconciliation->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(BankReconciliation $reconciliation): array
    {
        return $this->calculator->summary($reconciliation);
    }

    private function assertEditable(BankReconciliation $reconciliation): void
    {
        if (! $reconciliation->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'This reconciliation cannot be edited.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCreation(Company $company, array $data): void
    {
        if (empty($data['bank_account_id'])) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'The bank account is required.',
            ]);
        }

        if (empty($data['from_date'])) {
            throw ValidationException::withMessages([
                'from_date' => 'The from date is required.',
            ]);
        }

        if (empty($data['to_date'])) {
            throw ValidationException::withMessages([
                'to_date' => 'The to date is required.',
            ]);
        }

        if (! array_key_exists('statement_opening_balance', $data)) {
            throw ValidationException::withMessages([
                'statement_opening_balance' => 'The statement opening balance is required.',
            ]);
        }

        if (! array_key_exists('statement_closing_balance', $data)) {
            throw ValidationException::withMessages([
                'statement_closing_balance' => 'The statement closing balance is required.',
            ]);
        }
    }
}
