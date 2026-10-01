<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReceipt>
 */
class CustomerReceiptFactory extends Factory
{
    protected $model = CustomerReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'company_id' => fn (array $attributes) => Customer::findOrFail($attributes['customer_id'])->company_id,

            'receipt_number' => 'RCPT-'.fake()->unique()->numerify('######'),
            'receipt_date' => now()->toDateString(),
            'amount' => 100,
            'payment_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail($attributes['company_id']))
                ->asset()
                ->create(['code' => '1000', 'name' => 'Cash'])
                ->getKey(),
            'reference' => null,
            'notes' => null,
            'status' => PaymentStatus::Draft->value,
            'journal_id' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Posted->value,
            'posted_at' => now(),
        ]);
    }
}
