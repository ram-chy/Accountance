<?php

namespace Database\Factories;

use App\Enums\CashBankTransactionType;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashBankTransaction>
 */
class CashBankTransactionFactory extends Factory
{
    protected $model = CashBankTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'transaction_number' => 'CBN-'.fake()->unique()->numerify('######'),

            /*
             * A transfer by default, because it is the only type whose two
             * accounts are both unambiguously cash/bank accounts and therefore
             * needs no third account to be meaningful.
             */
            'transaction_type' => CashBankTransactionType::Transfer->value,
            'transaction_date' => now()->toDateString(),

            'source_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->bank()
                ->create()
                ->getKey(),

            'destination_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->bank()
                ->create()
                ->getKey(),

            'amount' => 100,
            'reference' => null,
            'notes' => null,
            'status' => PaymentStatus::Draft->value,
            'journal_id' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }

    public function deposit(): static
    {
        return $this->state(fn () => [
            'transaction_type' => CashBankTransactionType::Deposit->value,
        ]);
    }

    public function withdrawal(): static
    {
        return $this->state(fn () => [
            'transaction_type' => CashBankTransactionType::Withdrawal->value,
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn () => [
            'transaction_type' => CashBankTransactionType::Transfer->value,
        ]);
    }

    /**
     * Use specific accounts on both sides.
     *
     * @param  array{source_account_id?: int, destination_account_id?: int}  $accounts
     */
    public function accounts(array $accounts): static
    {
        return $this->state(fn () => array_filter([
            'source_account_id' => $accounts['source_account_id'] ?? null,
            'destination_account_id' => $accounts['destination_account_id'] ?? null,
        ], fn ($value) => $value !== null));
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Posted->value,
            'posted_at' => now(),
        ]);
    }
}
