<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInvoiceLine>
 */
class SalesInvoiceLineFactory extends Factory
{
    protected $model = SalesInvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_invoice_id' => SalesInvoice::factory(),

            /*
             * line_number is unique per invoice, so a factory that always wrote 1
             * would fail on a two-line invoice. Left to the caller via state
             * when the invoice is a real one; the default keeps single-line use
             * working.
             */
            'line_number' => 1,
            'description' => fake()->sentence(),

            /*
             * quantity x unit_price = 2 x 100.00 = 200.00, and every derived
             * column is consistent with that, so a test that reads these values
             * back is not reading a lie. The application recalculates them on
             * write regardless.
             */
            'quantity' => 2,
            'unit_price' => 100,
            'discount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 200,

            // For the INVOICE's company, not a new one.
            'revenue_account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail(
                    SalesInvoice::findOrFail($attributes['sales_invoice_id'])->company_id
                ))
                ->revenue()
                ->create(['code' => '4000', 'name' => 'Sales Revenue'])
                ->getKey(),
        ];
    }
}
