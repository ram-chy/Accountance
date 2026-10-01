<?php

namespace Tests\Feature\Transactions;

use App\Enums\JournalSource;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Purchase bill drafting and posting.
 *
 * The counterpart of SalesInvoiceTest, and the file exists separately because the
 * input tax direction is the single most error-prone part of the whole phase and
 * deserves its own assertions rather than a shared data provider.
 *
 * The entry is:
 *
 *   Dr Expense / Purchase   net line amounts, one line per expense account
 *   Dr Input Tax            tax_total, when non-zero
 *       Cr Accounts Payable grand_total
 */
class PurchaseBillTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function a_draft_bill_is_created_with_server_computed_totals(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '4',
                    'unit_cost' => '25.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', '100.0000')
            ->assertJsonPath('data.grand_total', '100.0000')
            ->assertJsonPath('data.status', TransactionStatus::Draft->value)
            ->assertJsonPath('data.bill_number', 'BILL-000001');
    }

    #[Test]
    public function posting_a_bill_debits_expense_and_credits_payable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $journal = Journal::findOrFail($bill->journal_id);

        $this->assertSame(JournalSource::PurchaseBill->value, $journal->source_type->value);
        $this->assertSame($bill->getKey(), $journal->source_id);

        $lines = $journal->lines()->get();

        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['expense']->getKey())->first()->debit);
        $this->assertSame('500.0000', (string) $lines->where('account_id', $accounts['payable']->getKey())->first()->credit);
    }

    #[Test]
    public function input_tax_is_debited_to_an_asset_not_credited_to_a_liability(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts, overrides: [
            'tax_account_id' => $accounts['input_tax']->getKey(),
            'lines' => [[
                'quantity' => '1', 'unit_cost' => '100.00', 'discount' => '0', 'tax_rate' => '20',
                'expense_account_id' => $accounts['expense']->getKey(),
            ]],
        ]);

        $lines = Journal::findOrFail($bill->journal_id)->lines()->get();
        $inputTaxLine = $lines->where('account_id', $accounts['input_tax']->getKey())->first();

        $this->assertNotNull($inputTaxLine, 'The input tax account must appear in the entry.');
        $this->assertSame('20.0000', (string) $inputTaxLine->debit);
        $this->assertSame('0.0000', (string) $inputTaxLine->credit);

        /*
         * The direction is the whole point. On a sales invoice the same 20.00 is
         * credited to a tax liability, because it is money held for the tax
         * authority. Recovered from a supplier it is money the authority owes
         * back, so it is a debit to an asset. Booking it the same way as the
         * sales tax would understate the asset and overstate the liability, and
         * the trial balance would still balance - so nothing downstream would
         * notice.
         */
        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['expense']->getKey())->first()->debit);
        $this->assertSame('120.0000', (string) $lines->where('account_id', $accounts['payable']->getKey())->first()->credit);
    }

    #[Test]
    public function the_sales_tax_liability_account_is_refused_as_an_input_tax_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                // The correct account for a sales invoice, and the wrong one here.
                'tax_account_id' => $accounts['tax_payable']->getKey(),
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '100.00', 'discount' => '0', 'tax_rate' => '20',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])->assertCreated();

        /*
         * The type is checked by TransactionAccountResolver, which is the single
         * place account rules live, and it runs when the entry is written rather
         * than when the document is drafted. That is the same point at which the
         * payable, receivable and payment accounts are checked, so the account
         * rules stay in one component instead of being duplicated in six request
         * classes. A bill that survives here still refuses to post.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/purchase-bills/{$created->json('data.id')}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_account_id');

        $this->assertSame(0, Journal::where('source_type', JournalSource::PurchaseBill->value)->count());
        $this->assertNull(
            PurchaseBill::findOrFail($created->json('data.id'))->journal_id,
            'A refused post must leave the bill undrafted from the ledger.'
        );
    }

    #[Test]
    public function a_taxed_bill_without_an_input_tax_account_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '100.00', 'discount' => '0', 'tax_rate' => '20',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_account_id');
    }

    #[Test]
    public function a_revenue_account_cannot_be_used_as_a_line_expense_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.expense_account_id');
    }

    #[Test]
    public function the_payable_account_must_be_a_liability(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/suppliers', [
                'supplier_code' => 'WRONGPAY',
                'name' => 'Wrong payable type',
                'receivable_account_id' => null,
                'payable_account_id' => $accounts['cash']->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payable_account_id');
    }

    #[Test]
    public function a_bill_cannot_be_posted_twice(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/purchase-bills/{$bill->getKey()}/post")
            ->assertStatus(409);

        $this->assertSame(1, Journal::where('source_type', JournalSource::PurchaseBill->value)
            ->where('source_id', $bill->getKey())
            ->count());
    }

    #[Test]
    public function a_posted_bill_cannot_be_edited_or_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/purchase-bills/{$bill->getKey()}", ['notes' => 'Tampered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bill');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/purchase-bills/{$bill->getKey()}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bill');
    }

    #[Test]
    public function a_draft_bill_can_be_deleted_and_its_number_is_not_reused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $first = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])->assertCreated();

        $billId = $first->json('data.id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/purchase-bills/{$billId}")
            ->assertSuccessful();

        $second = $this->postBill($user, $company, $supplier, $accounts, date: '2027-01-11');

        $this->assertSame('BILL-000002', $second->bill_number);
    }

    #[Test]
    public function a_bill_cannot_be_posted_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->makePeriodFor($company, '2027-01-10', 'Closed January', closed: true);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/purchase-bills/{$response->json('data.id')}/post")
            ->assertUnprocessable();
    }

    #[Test]
    public function an_inactive_supplier_cannot_be_billed(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $supplier->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier_id');
    }

    #[Test]
    public function a_supplier_with_posted_bills_cannot_be_deactivated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/suppliers/{$supplier->getKey()}/deactivate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supplier');
    }

    #[Test]
    public function settlement_figures_are_derived_not_stored(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $bill = $this->postBill($user, $company, $supplier, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/purchase-bills/{$bill->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.paid_total', '0.0000')
            ->assertJsonPath('data.balance_due', '500.0000');

        $this->assertFalse(Schema::hasColumn('purchase_bills', 'paid_total'));
    }

    #[Test]
    public function a_bill_from_another_company_is_not_found(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();
        $otherAccounts = $this->makeTransactionAccounts($other);

        $foreignSupplier = Supplier::factory()->for($other)->create([
            'payable_account_id' => $otherAccounts['payable']->getKey(),
        ]);

        $foreignBill = PurchaseBill::factory()->for($other)->for($foreignSupplier)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/purchase-bills/{$foreignBill->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function the_outstanding_filter_excludes_drafts(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->postBill($user, $company, $supplier, $accounts, date: '2027-01-10');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-15',
                'due_date' => '2027-02-15',
                'lines' => [[
                    'quantity' => '1', 'unit_cost' => '99.00', 'discount' => '0', 'tax_rate' => '0',
                    'expense_account_id' => $accounts['expense']->getKey(),
                ]],
            ])->assertCreated();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/purchase-bills?outstanding=1')
            ->assertSuccessful();

        $this->assertCount(1, $response->json('data'));
    }
}
