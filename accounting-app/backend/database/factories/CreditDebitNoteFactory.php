<?php

namespace Database\Factories;

use App\Enums\NoteType;
use App\Enums\TransactionStatus;
use App\Models\CreditDebitNote;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditDebitNote>
 */
class CreditDebitNoteFactory extends Factory
{
    protected $model = CreditDebitNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            /*
             * note_type first, because everything else in this factory derives
             * from it: which source document exists, which counterparty is set,
             * and which of the two source columns is null. Eloquent resolves
             * factory attributes in declaration order, so a state() that changes
             * the type after the definition has run must restate the source - which
             * is what salesCreditNote() and the other three do.
             */
            'note_type' => NoteType::SalesCreditNote->value,

            'sales_invoice_id' => fn () => SalesInvoice::factory()->create([
                /*
                 * A posted invoice. The adjustment service refuses a draft source
                 * document, so a factory that defaulted to DRAFT would produce a
                 * note that no code path in the application could have created -
                 * the same objection SalesInvoiceFactory records about inventing
                 * totals.
                 */
                'status' => TransactionStatus::Posted->value,
            ])->getKey(),

            'purchase_bill_id' => null,

            'customer_id' => fn (array $attributes) => SalesInvoice::findOrFail($attributes['sales_invoice_id'])->customer_id,
            'supplier_id' => null,

            'company_id' => fn (array $attributes) => SalesInvoice::findOrFail($attributes['sales_invoice_id'])->company_id,

            'note_number' => 'CDN-'.fake()->unique()->numerify('######'),
            'note_date' => now()->toDateString(),
            'status' => TransactionStatus::Draft->value,

            /*
             * Totals are left at zero rather than invented. They are server-
             * computed from the lines on every write, and a factory that wrote a
             * plausible grand_total would create rows the application cannot
             * produce. Use the API (CreditDebitNoteTestCase helpers) for a note
             * that needs to be posted, so the real calculator and the real posting
             * engine are what the test exercises.
             */
            'subtotal' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
            'grand_total' => 0,

            'reason' => fake()->sentence(),
            'reference' => null,
            'notes' => null,
            'tax_account_id' => null,
            'journal_id' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }

    /**
     * A draft sales credit note against a fresh posted invoice.
     */
    public function salesCreditNote(): static
    {
        return $this->state(fn (): array => [
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => fn () => SalesInvoice::factory()->create([
                'status' => TransactionStatus::Posted->value,
            ])->getKey(),
            'purchase_bill_id' => null,
            'customer_id' => fn (array $attributes) => SalesInvoice::findOrFail($attributes['sales_invoice_id'])->customer_id,
            'supplier_id' => null,
            'company_id' => fn (array $attributes) => SalesInvoice::findOrFail($attributes['sales_invoice_id'])->company_id,
        ]);
    }

    /**
     * A draft sales debit note against a fresh posted invoice.
     */
    public function salesDebitNote(): static
    {
        return $this->salesCreditNote()->state(fn (): array => [
            'note_type' => NoteType::SalesDebitNote->value,
        ]);
    }

    /**
     * A draft purchase credit note against a fresh posted bill.
     */
    public function purchaseCreditNote(): static
    {
        return $this->state(fn (): array => [
            'note_type' => NoteType::PurchaseCreditNote->value,
            'sales_invoice_id' => null,
            'purchase_bill_id' => fn () => PurchaseBill::factory()->create([
                'status' => TransactionStatus::Posted->value,
            ])->getKey(),
            'customer_id' => null,
            'supplier_id' => fn (array $attributes) => PurchaseBill::findOrFail($attributes['purchase_bill_id'])->supplier_id,
            'company_id' => fn (array $attributes) => PurchaseBill::findOrFail($attributes['purchase_bill_id'])->company_id,
        ]);
    }

    /**
     * A draft purchase debit note against a fresh posted bill.
     */
    public function purchaseDebitNote(): static
    {
        return $this->purchaseCreditNote()->state(fn (): array => [
            'note_type' => NoteType::PurchaseDebitNote->value,
        ]);
    }

    /**
     * A posted note.
     *
     * `posted_by` is filled in because the credit_debit_notes_posted_fields_check
     * constraint refuses a POSTED row without a poster - a state the application
     * would never produce, so a factory that tried would simply fail. What this
     * state still does NOT set is journal_id: a note that says POSTED with no
     * journal is exactly the incoherence the phase exists to prevent, and no test
     * should construct one by accident. A test that needs a genuinely posted note
     * should post it through the API, so the real calculation, the real
     * adjustment check and the real posting engine are what the test exercises.
     */
    public function posted(): static
    {
        return $this->state(fn (): array => [
            'status' => TransactionStatus::Posted->value,
            'posted_by' => User::factory(),
            'posted_at' => now(),
        ]);
    }

    /**
     * Against a specific invoice, rather than a fresh one.
     */
    public function forInvoice(SalesInvoice $invoice): static
    {
        return $this->state(fn (): array => [
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => $invoice->getKey(),
            'purchase_bill_id' => null,
            'customer_id' => $invoice->customer_id,
            'supplier_id' => null,
            'company_id' => $invoice->company_id,
        ]);
    }

    /**
     * Against a specific bill, rather than a fresh one.
     */
    public function forBill(PurchaseBill $bill): static
    {
        return $this->state(fn (): array => [
            'note_type' => NoteType::PurchaseCreditNote->value,
            'sales_invoice_id' => null,
            'purchase_bill_id' => $bill->getKey(),
            'customer_id' => null,
            'supplier_id' => $bill->supplier_id,
            'company_id' => $bill->company_id,
        ]);
    }
}
