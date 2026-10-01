<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'parent_id' => null,
            /*
             * Codes are unique per company, so a sequence keeps generated
             * accounts from colliding. Str::padLeft rather than a random string
             * because a chart of accounts is read by humans comparing numbers
             * column-wise, and a test that builds a five-account chart should
             * produce a readable one.
             */
            'code' => (string) fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->unique()->words(2, true),
            'account_type' => AccountType::Asset->value,
            'normal_balance' => null,
            'description' => null,
            'is_active' => true,
            'is_system' => false,
        ];
    }

    public function type(AccountType $type): static
    {
        return $this->state(fn () => [
            'account_type' => $type->value,
            // No contra override unless asked for, so the account follows its
            // type's normal balance by default.
            'normal_balance' => null,
        ]);
    }

    public function asset(): static
    {
        return $this->type(AccountType::Asset);
    }

    public function liability(): static
    {
        return $this->type(AccountType::Liability);
    }

    public function equity(): static
    {
        return $this->type(AccountType::Equity);
    }

    public function revenue(): static
    {
        return $this->type(AccountType::Revenue);
    }

    public function expense(): static
    {
        return $this->type(AccountType::Expense);
    }

    /**
     * A contra account: explicit normal balance opposite to its type, which is
     * how accumulated depreciation and sales returns are modelled.
     */
    public function contra(): static
    {
        return $this->state(fn (array $attributes) => [
            'normal_balance' => AccountType::from($attributes['account_type'])
                ->normalBalance()
                ->opposite()
                ->value,
        ]);
    }

    /**
     * An explicit normal-balance override, for testing contra behaviour directly.
     */
    public function normalBalance(NormalBalance $balance): static
    {
        return $this->state(fn () => ['normal_balance' => $balance->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }

    public function childOf(Account $parent): static
    {
        return $this->state(fn () => [
            'company_id' => $parent->company_id,
            'parent_id' => $parent->getKey(),
        ]);
    }
}
