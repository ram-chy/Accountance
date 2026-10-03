<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\CreditDebitNoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditDebitNoteLine>
 */
class CreditDebitNoteLineFactory extends Factory
{
    protected $model = CreditDebitNoteLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_debit_note_id' => CreditDebitNote::factory(),

            /*
             * line_number is unique per note, so a factory that always wrote 1
             * would fail on a two-line note. Same reasoning and same default as
             * SalesInvoiceLineFactory.
             */
            'line_number' => 1,
            'description' => fake()->sentence(),

            /*
             * 2 x 100.00 = 200.00, with every derived column consistent with that,
             * so a test reading these back is not reading a lie.
             */
            'quantity' => 2,
            'unit_price' => 100,
            'discount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'line_total' => 200,

            /*
             * Document-level adjustment by default: no source line. A line-level
             * note names one, and CreditDebitNoteAdjustmentService checks that it
             * belongs to the note's own source document and that its quantity has
             * not already been consumed.
             */
            'sales_invoice_line_id' => null,
            'purchase_bill_line_id' => null,

            'tax_id' => null,

            /*
             * A REVENUE account, because the default note is a sales note. The
             * account is created in the note's own company rather than a new one -
             * a revenue account belonging to a different tenant would be refused by
             * every path that reads it.
             */
            'account_id' => fn (array $attributes) => Account::factory()
                ->for(Company::findOrFail(
                    CreditDebitNote::findOrFail($attributes['credit_debit_note_id'])->company_id
                ))
                ->revenue()
                ->create(['code' => '4000', 'name' => 'Sales Revenue'])
                ->getKey(),
        ];
    }
}
