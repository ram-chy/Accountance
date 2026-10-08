<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 */
class BudgetLineFactory extends Factory
{
    protected $model = BudgetLine::class;

    /**
     * Every line owns the budget, the account and the period it references, and
     * all three are pinned to the SAME company.
     *
     * That pinning is the whole point of resolving through the budget_id. A bare
     * Account::factory() creates an account in a fresh company, and a line that
     * plans an expense for a company it does not belong to is not a fixture - it
     * is a row BudgetLineService refuses on isolation grounds, so every test
     * using this factory would fail on a company error while appearing to test
     * something else.
     *
     * The default account is an EXPENSE and the default amount is positive,
     * matching the common budgeting case. Tests that need revenue lines switch
     * the account, which is a single state change rather than a rebuild.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($this->companyId($attributes)))
                ->expense()
                ->create()
                ->getKey(),
            'accounting_period_id' => fn (array $attributes) => AccountingPeriod::factory()
                ->for(Company::findOrFail($this->companyId($attributes)))
                ->create()
                ->getKey(),
            'amount' => fake()->randomFloat(4, 100, 100000),
            'description' => null,
        ];
    }

    public function forBudget(Budget $budget): static
    {
        return $this->state(fn () => [
            'budget_id' => $budget->getKey(),
        ]);
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn () => [
            'account_id' => $account->getKey(),
        ]);
    }

    public function forPeriod(AccountingPeriod $period): static
    {
        return $this->state(fn () => [
            'accounting_period_id' => $period->getKey(),
        ]);
    }

    public function amount(int|float|string $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    /**
     * The company the line's budget belongs to, so the dependent account and
     * period can be created in it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function companyId(array $attributes): int
    {
        return (int) Budget::findOrFail($attributes['budget_id'])->company_id;
    }
}
