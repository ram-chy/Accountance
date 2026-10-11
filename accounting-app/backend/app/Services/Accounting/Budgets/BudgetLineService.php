<?php

namespace App\Services\Accounting\Budgets;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use App\Models\User;
use App\Services\Accounting\Dimensions\DimensionAssignmentValidator;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Budget line maintenance.
 *
 * A line says "this account, this period, this planned amount". Three facts make
 * a line valid that the database cannot check on its own, because the columns it
 * would need to compare belong to other tables:
 *
 *  1. The account must belong to the SAME company as the budget. A line has no
 *     company_id, so this can only be checked by resolving the account through
 *     the company before the insert.
 *  2. The account must be a profit-and-loss account - Revenue or Expense.
 *     Budgeting a balance-sheet account needs opening balances and cash flow to
 *     mean anything, and the phase deliberately scopes that out (see
 *     PHASE_16_REPORT.md). Allowing a balance-sheet line would produce a variance
 *     against a cumulative balance that is not comparable to a period plan.
 *  3. The period must belong to the budget's own financial year, so a line cannot
 *     plan activity for a month the budget does not cover.
 *
 * Ownership and the budget's DRAFT status are checked on every write. An approved
 * budget's lines are frozen: the only way to change an approved plan is to revise
 * it into a new version, which BudgetService::revise() does by copying.
 *
 * The (budget_id, account_id, accounting_period_id) unique index is the real
 * guard against a duplicate line; the check here exists to produce a human error
 * message, and the insert is still wrapped so a racing request gets the same
 * message rather than a 500.
 */
class BudgetLineService
{
    public function __construct(
        private readonly DimensionAssignmentValidator $dimensions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Budget $budget, Company $company, User $actor, array $data): BudgetLine
    {
        $this->assertBudgetOfCompany($budget, $company);

        return DB::transaction(function () use ($budget, $company, $actor, $data) {
            $fresh = $this->lockBudget($budget);

            $this->assertDraft($fresh);

            $account = $this->resolveAccount($company, (int) $data['account_id']);
            $period = $this->resolvePeriod($fresh, (int) $data['accounting_period_id']);

            $this->assertNoDuplicate($fresh, $account, $period, null);

            $line = $this->write($fresh, $account, $period, $data);

            $this->replaceDimensions($company, $line, $data['dimensions'] ?? []);

            $this->touch($fresh, $actor);

            return $line;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(BudgetLine $line, Budget $budget, Company $company, User $actor, array $data): BudgetLine
    {
        $this->assertBudgetOfCompany($budget, $company);

        return DB::transaction(function () use ($line, $budget, $company, $actor, $data) {
            $fresh = $this->lockBudget($budget);

            $this->assertDraft($fresh);

            $lockedLine = $this->lockLine($fresh, $line);

            $account = isset($data['account_id'])
                ? $this->resolveAccount($company, (int) $data['account_id'])
                : $lockedLine->account;

            $period = isset($data['accounting_period_id'])
                ? $this->resolvePeriod($fresh, (int) $data['accounting_period_id'])
                : $lockedLine->accountingPeriod;

            $this->assertNoDuplicate($fresh, $account, $period, $lockedLine->getKey());

            $lockedLine->fill([
                'account_id' => $account->getKey(),
                'accounting_period_id' => $period->getKey(),
                'amount' => array_key_exists('amount', $data)
                    ? Money::of($data['amount'])->toDatabase()
                    : $lockedLine->getRawOriginal('amount'),
                'description' => array_key_exists('description', $data) ? $data['description'] : $lockedLine->description,
            ]);

            $this->saveGuardingDuplicate($lockedLine);

            if (array_key_exists('dimensions', $data)) {
                // Full replace, matching journal-line semantics: a line has one
                // value per dimension type, so a present key - even an empty
                // array, which clears the labels - is the whole set.
                $this->replaceDimensions($company, $lockedLine, $data['dimensions']);
            }

            $this->touch($fresh, $actor);

            return $lockedLine->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function delete(BudgetLine $line, Budget $budget, Company $company, User $actor): void
    {
        $this->assertBudgetOfCompany($budget, $company);

        DB::transaction(function () use ($line, $budget, $actor) {
            $fresh = $this->lockBudget($budget);

            $this->assertDraft($fresh);

            $lockedLine = $this->lockLine($fresh, $line);

            $lockedLine->delete();

            $this->touch($fresh, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(Budget $budget, Account $account, AccountingPeriod $period, array $data): BudgetLine
    {
        $line = new BudgetLine;

        $line->fill([
            'account_id' => $account->getKey(),
            'accounting_period_id' => $period->getKey(),
            'amount' => Money::of($data['amount'])->toDatabase(),
            'description' => $data['description'] ?? null,
        ]);

        $line->budget_id = $budget->getKey();

        $this->saveGuardingDuplicate($line);

        return $line->refresh();
    }

    /**
     * @throws ValidationException
     */
    private function resolveAccount(Company $company, int $accountId): Account
    {
        $account = Account::query()
            ->where('company_id', $company->getKey())
            ->find($accountId);

        if ($account === null) {
            throw ValidationException::withMessages([
                'account_id' => 'The selected account does not belong to the active company.',
            ]);
        }

        if (! in_array($account->account_type, [AccountType::Revenue, AccountType::Expense], true)) {
            throw ValidationException::withMessages([
                'account_id' => 'A budget line may only reference a revenue or expense account.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account_id' => 'The selected account is inactive and cannot be budgeted against.',
            ]);
        }

        return $account;
    }

    /**
     * @throws ValidationException
     */
    private function resolvePeriod(Budget $budget, int $periodId): AccountingPeriod
    {
        $period = AccountingPeriod::query()
            ->where('company_id', $budget->company_id)
            ->find($periodId);

        if ($period === null) {
            throw ValidationException::withMessages([
                'accounting_period_id' => 'The selected accounting period does not belong to the active company.',
            ]);
        }

        if ((int) $period->financial_year_id !== (int) $budget->financial_year_id) {
            throw ValidationException::withMessages([
                'accounting_period_id' => 'The selected accounting period does not belong to the budget\'s financial year.',
            ]);
        }

        return $period;
    }

    /**
     * @throws ValidationException
     */
    private function assertNoDuplicate(Budget $budget, Account $account, AccountingPeriod $period, ?int $exceptId): void
    {
        $exists = BudgetLine::query()
            ->where('budget_id', $budget->getKey())
            ->where('account_id', $account->getKey())
            ->where('accounting_period_id', $period->getKey())
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'account_id' => sprintf(
                    'Account [%s] already has a budgeted amount for period [%s] in this budget.',
                    $account->code,
                    $period->name
                ),
            ]);
        }
    }

    private function touch(Budget $budget, User $actor): void
    {
        $budget->forceFill(['updated_by' => $actor->getKey()])->save();
    }

    /**
     * Validate and persist a line's dimension labels.
     *
     * The shared validator checks ownership, activity and value-vs-dimension
     * against the ACTIVE company - a dimension from another company cannot be
     * accepted because the junction table carries no company of its own, so the
     * company boundary lives at this resolve step. Errors are raised as a
     * whole-line validation failure through the `dimensions` field path.
     *
     * @throws ValidationException
     */
    private function replaceDimensions(Company $company, BudgetLine $line, array $assignments): void
    {
        $errors = [];

        $resolved = $this->dimensions->resolve($company, $assignments, 'dimensions', $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $line->budgetLineDimensions()->delete();

        foreach ($resolved as $assignment) {
            $line->budgetLineDimensions()->create([
                'financial_dimension_id' => $assignment['financial_dimension_id'],
                'financial_dimension_value_id' => $assignment['financial_dimension_value_id'],
            ]);
        }
    }

    private function assertBudgetOfCompany(Budget $budget, Company $company): void
    {
        if ((int) $budget->company_id !== (int) $company->getKey()) {
            throw (new ModelNotFoundException)->setModel(Budget::class, [$budget->getKey()]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(Budget $budget): void
    {
        if (! $budget->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'An approved budget is immutable. Raise a new version to change its lines.',
            ]);
        }
    }

    private function lockBudget(Budget $budget): Budget
    {
        return Budget::query()->whereKey($budget->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockLine(Budget $budget, BudgetLine $line): BudgetLine
    {
        $locked = BudgetLine::query()
            ->where('budget_id', $budget->getKey())
            ->whereKey($line->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw (new ModelNotFoundException)->setModel(BudgetLine::class, [$line->getKey()]);
        }

        return $locked;
    }

    /**
     * Persist, translating the natural-key unique violation into the same
     * validation message the pre-check would have produced.
     */
    private function saveGuardingDuplicate(BudgetLine $line): void
    {
        try {
            $line->save();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'account_id' => 'This account already has a budgeted amount for that period in this budget.',
                ]);
            }

            throw $e;
        }
    }
}
