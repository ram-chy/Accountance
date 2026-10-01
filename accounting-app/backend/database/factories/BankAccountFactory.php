<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),

            /*
             * The default account is a bank account, because a BankAccount row
             * for an account that is not classified as one would be rejected by
             * CashBankAccountService. The factory produces a legitimate row
             * without the caller having to remember that.
             */
            'account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->bank()
                ->create()
                ->getKey(),

            'account_name' => fake()->words(2, true),
            'bank_name' => fake()->company(),
            'account_number' => fake()->numerify('##########'),
            'branch' => fake()->city(),
            'bank_identifier' => strtoupper(fake()->bothify('????####')),
            'is_active' => true,
        ];
    }

    /**
     * Attach these details to an existing bank account rather than creating one.
     */
    public function forAccount(Account $account): static
    {
        return $this->state(fn () => [
            'company_id' => $account->company_id,
            'account_id' => $account->getKey(),
        ]);
    }

    /**
     * Bank details that are no longer to be used for new movements.
     */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
