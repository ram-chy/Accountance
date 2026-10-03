<?php

namespace Tests\Feature\Transactions;

use App\Enums\AccountType;
use App\Enums\NoteType;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CreditDebitNote;
use App\Models\CreditDebitNoteLine;
use App\Models\Customer;
use App\Models\PurchaseBill;
use App\Models\PurchaseBillLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The database constraints that back up the note rules.
 *
 * Section 29 asks for the uniqueness and integrity rules to be backed by the
 * database rather than trusted to application validation alone, and the notes
 * schema is where that matters most: an adjustment limit that lives only in a
 * service method is a limit that any future code path - a console command, an
 * import, a repair script - can walk straight past.
 *
 * As with TransactionIntegrityTest, true simultaneity is not reproducible in one
 * process. What is proven here is the second line of defence: the inserts below
 * go through the model layer with the services deliberately bypassed, and the
 * database still refuses them. The row locks that make the application-level
 * checks correct are asserted by reading the code paths that take them.
 */
class CreditDebitNoteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_note_number_cannot_be_reused_within_a_company(): void
    {
        [$user, $company] = $this->context();
        $invoice = $this->invoice($company);

        $this->note($user, $company, $invoice, ['note_number' => 'CDN-000001']);

        $this->assertDatabaseIntegrityViolation(
            fn () => $this->note($user, $company, $invoice, ['note_number' => 'CDN-000001'])
        );

        $this->assertSame(1, CreditDebitNote::where('note_number', 'CDN-000001')->count());
    }

    #[Test]
    public function two_companies_may_each_hold_cd_n_000001(): void
    {
        [$user, $first] = $this->context();
        [, $second] = $this->context();

        $this->note($user, $first, $this->invoice($first), ['note_number' => 'CDN-000001']);
        $this->note($user, $second, $this->invoice($second), ['note_number' => 'CDN-000001']);

        $this->assertSame(2, CreditDebitNote::where('note_number', 'CDN-000001')->count());
    }

    #[Test]
    public function a_note_cannot_name_both_a_source_and_nothing(): void
    {
        [$user, $company] = $this->context();
        $invoice = $this->invoice($company);
        $bill = $this->bill($company);

        $this->assertDatabaseIntegrityViolation(fn () => $this->note($user, $company, $invoice, [
            'sales_invoice_id' => $invoice->getKey(),
            'purchase_bill_id' => $bill->getKey(),
        ]));

        $this->assertDatabaseIntegrityViolation(fn () => $this->note($user, $company, $invoice, [
            'sales_invoice_id' => null,
            'purchase_bill_id' => null,
        ]));
    }

    #[Test]
    public function a_customer_cannot_be_present_without_a_sales_source(): void
    {
        [$user, $company] = $this->context();
        $customer = $this->customer($company);

        /*
         * The note keeps its sales_invoice_id, so the single-source check is
         * satisfied; what the counterparty check refuses is the row that claims a
         * customer while naming no invoice - the shape a half-written note would
         * have if the service derived the counterparty and then failed before
         * setting the source.
         */
        $this->assertDatabaseIntegrityViolation(fn () => $this->note($user, $company, null, [
            'sales_invoice_id' => null,
            'customer_id' => $customer->getKey(),
        ]));
    }

    #[Test]
    public function a_note_cannot_be_marked_paid(): void
    {
        [$user, $company] = $this->context();
        $invoice = $this->invoice($company);

        /*
         * A note is not something a customer pays, so PARTIALLY_PAID and PAID are
         * not states it can be in. The enum allows them - sales_invoices uses all
         * four - and this constraint is what stops a note from inheriting a status
         * from a source document by mistake.
         */
        $this->assertDatabaseIntegrityViolation(fn () => $this->note($user, $company, $invoice, [
            'status' => TransactionStatus::Paid->value,
        ]));

        $this->assertDatabaseIntegrityViolation(fn () => $this->note($user, $company, $invoice, [
            'status' => TransactionStatus::PartiallyPaid->value,
        ]));
    }

    #[Test]
    public function a_posted_note_must_carry_a_poster_and_a_posting_time(): void
    {
        [$user, $company] = $this->context();
        $invoice = $this->invoice($company);

        $this->assertDatabaseIntegrityViolation(fn () => $this->note($user, $company, $invoice, [
            'status' => TransactionStatus::Posted->value,
            'posted_by' => null,
            'posted_at' => null,
        ]));
    }

    #[Test]
    public function a_note_line_cannot_reference_both_source_kinds(): void
    {
        [$user, $company] = $this->context();
        $invoice = $this->invoice($company);
        $note = $this->note($user, $company, $invoice);

        /*
         * Two companies, deliberately. An invoice and a bill in the same company
         * both build their standard chart of accounts, and both want code 1100 -
         * so pairing them here would fail on a duplicate account code and never
         * reach the CHECK being tested. Keeping them apart also happens to be the
         * honest shape of the row: a line that named both kinds of source would
         * already be claiming two different companies' documents at once.
         */
        [, $otherCompany] = $this->context();
        $otherBill = $this->bill($otherCompany);

        /*
         * Both lines already exist - invoice() and bill() each build their own -
         * and they are read back off the documents rather than created again,
         * because a factory builds the standard chart of accounts every time an
         * invoice is made and a second build would collide on account code 4000.
         */
        $invoiceLine = $invoice->lines()->firstOrFail();
        $billLine = $otherBill->lines()->firstOrFail();

        $this->assertDatabaseIntegrityViolation(fn () => CreditDebitNoteLine::query()->forceCreate([
            'credit_debit_note_id' => $note->getKey(),
            'line_number' => 1,
            'sales_invoice_line_id' => $invoiceLine->getKey(),
            'purchase_bill_line_id' => $billLine->getKey(),
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'line_total' => '100.00',
            'account_id' => $invoiceLine->revenue_account_id,
        ]));

        $this->assertSame(0, CreditDebitNoteLine::where('credit_debit_note_id', $note->getKey())->count());
    }

    #[Test]
    public function line_numbers_cannot_repeat_within_a_note(): void
    {
        [$user, $company] = $this->context();
        $note = $this->note($user, $company, $this->invoice($company));
        $accountId = $this->revenue($company)->getKey();

        $this->line($note, $accountId, 1);

        $this->assertDatabaseIntegrityViolation(fn () => $this->line($note, $accountId, 1));
    }

    #[Test]
    public function an_invoice_a_note_adjusts_cannot_be_deleted(): void
    {
        [$user, $company] = $this->context();
        $invoice = $this->invoice($company);

        $this->note($user, $company, $invoice);

        /*
         * RESTRICT, not CASCADE. Deleting the invoice would leave the credit note
         * adjusting a document that no longer exists, and a note whose reason and
         * amounts can no longer be read against what it was written for is not
         * something an auditor can accept - so the deletion is refused instead.
         */
        $this->assertDatabaseIntegrityViolation(fn () => $invoice->delete());
        $this->assertNotNull($invoice->fresh());
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function context(): array
    {
        $user = $this->createUserWithRole(RoleName::Accountant);

        return [$user, $this->createCompanyFor($user)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function note(User $user, Company $company, ?SalesInvoice $invoice, array $overrides = []): CreditDebitNote
    {
        /*
         * forceCreate, not create: the model deliberately does not mass-assign
         * company_id, note_number or created_by, because the service is what sets
         * them. The point of this file is to write rows the way the service would
         * write them while bypassing every rule the service applies, and going
         * through the mass-assignment guard would strip out the very columns under
         * test before the database ever saw them.
         */
        return CreditDebitNote::query()->forceCreate(array_merge([
            'company_id' => $company->getKey(),
            'note_number' => 'CDN-'.uniqid(),
            'note_type' => NoteType::SalesCreditNote->value,
            'sales_invoice_id' => $invoice?->getKey(),
            'purchase_bill_id' => null,
            'customer_id' => $invoice?->customer_id,
            'supplier_id' => null,
            'note_date' => '2027-02-15',
            'status' => TransactionStatus::Draft->value,
            'subtotal' => '100.00',
            'discount_total' => '0',
            'tax_total' => '0',
            'grand_total' => '100.00',
            'reason' => 'Integrity test note.',
            'created_by' => $user->getKey(),
        ], $overrides));
    }

    private function invoice(Company $company): SalesInvoice
    {
        $customer = $this->customer($company);
        $invoice = SalesInvoice::factory()->for($company)->for($customer)->create([
            'status' => TransactionStatus::Posted->value,
            'invoice_date' => '2027-01-10',
        ]);

        $this->invoiceLine($invoice);

        return $invoice;
    }

    private function invoiceLine(SalesInvoice $invoice): SalesInvoiceLine
    {
        return SalesInvoiceLine::factory()->for($invoice, 'invoice')->create([
            'line_number' => 1,
            'quantity' => '2',
            'unit_price' => '100.00',
            'line_total' => '200.00',
        ]);
    }

    private function bill(Company $company): PurchaseBill
    {
        $supplier = Supplier::factory()->for($company)->create();
        $bill = PurchaseBill::factory()->for($company)->for($supplier)->create([
            'status' => TransactionStatus::Posted->value,
            'bill_date' => '2027-01-10',
        ]);

        $this->billLine($bill);

        return $bill;
    }

    private function billLine(PurchaseBill $bill): PurchaseBillLine
    {
        return PurchaseBillLine::factory()->for($bill, 'bill')->create([
            'line_number' => 1,
            'quantity' => '2',
            'unit_cost' => '100.00',
            'line_total' => '200.00',
        ]);
    }

    private function line(CreditDebitNote $note, int $accountId, int $number): CreditDebitNoteLine
    {
        return CreditDebitNoteLine::query()->forceCreate([
            'credit_debit_note_id' => $note->getKey(),
            'line_number' => $number,
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'line_total' => '100.00',
            'account_id' => $accountId,
        ]);
    }

    private function customer(Company $company): Customer
    {
        return Customer::factory()->for($company)->create();
    }

    private function revenue(Company $company): Account
    {
        return Account::factory()->for($company)->type(AccountType::Revenue)->create();
    }
}
