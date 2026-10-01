<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseBill>
 */
class PurchaseBillFactory extends Factory
{
    protected $model = PurchaseBill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'company_id' => fn (array $attributes) => Supplier::findOrFail($attributes['supplier_id'])->company_id,

            'bill_number' => 'BILL-'.fake()->unique()->numerify('######'),
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'status' => TransactionStatus::Draft->value,
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'notes' => null,
            'tax_account_id' => null,
            'journal_id' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => TransactionStatus::Posted->value,
            'posted_at' => now(),
        ]);
    }
}
