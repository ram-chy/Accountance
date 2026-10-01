<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInvoice>
 */
class SalesInvoiceFactory extends Factory
{
    protected $model = SalesInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /*
         * A draft invoice: no journal, no totals beyond zero, created by a user.
         *
         * This factory intentionally does NOT try to produce a realistic
         * invoice. Totals and journal links are server-computed, and a factory
         * that invented them would create rows the application cannot itself
         * create, which is how a test ends up asserting on data that only exists
         * because a test made it up. Use the API (SalesInvoiceTestCase helpers)
         * for invoices that need to be posted, so the real calculation and the
         * real posting engine are what the test exercises.
         */
        return [
            // customer_id first: company_id is derived from it, and Eloquent
            // resolves factory attributes in declaration order.
            'customer_id' => Customer::factory(),
            'company_id' => fn (array $attributes) => Customer::findOrFail($attributes['customer_id'])->company_id,

            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_date' => now()->toDateString(),
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
