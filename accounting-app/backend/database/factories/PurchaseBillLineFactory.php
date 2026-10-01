<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseBillLine>
 */
class PurchaseBillLineFactory extends Factory
{
    protected $model = PurchaseBillLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_bill_id' => PurchaseBill::factory(),
            'line_number' => 1,
            'description' => fake()->sentence(),
            'quantity' => 2,
            'unit_cost' => 100,
            'discount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 200,
            'expense_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail(
                    PurchaseBill::findOrFail($attributes['purchase_bill_id'])->company_id
                ))
                ->expense()
                ->create(['code' => '6000', 'name' => 'Purchases / Cost of Sales'])
                ->getKey(),
        ];
    }
}
