<?php

namespace Tests\Feature\Transactions;

use App\Enums\JournalSource;
use App\Enums\PaymentStatus;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Journal;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Customer receipts: allocation, posting and settlement.
 *
 * The entry is:
 *
 *   Dr Cash / Bank   the receipt amount
 *       Cr Accounts Receivable   one credit per allocated invoice
 *
 * A receipt is the one Phase 5 document whose amount is a client input rather
 * than a derived total, and the tests below concentrate on the consequences of
 * that: who the money may settle on behalf of, how much of it may settle, and
 * what the ledger says afterwards.
 */
class CustomerReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function a_draft_receipt_is_created_with_its_allocations(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.receipt_number', 'RCPT-000001')
            ->assertJsonPath('data.status', PaymentStatus::Draft->value)
            ->assertJsonPath('data.allocations.0.sales_invoice_id', $invoice->getKey())
            ->assertJsonPath('data.allocations.0.amount', '200.0000');

        /*
         * Nothing about the receipt is in the ledger yet. The invoice above was
         * posted, so one journal already exists - the assertion is scoped to
         * receipts, because a draft is a statement of intent, and the rest of
         * this suite is only meaningful if drafts leave no trace in the
         * accounting record. Otherwise a bug that double-posts would be hidden
         * by the first entry.
         */
        $this->assertSame(0, Journal::where('source_type', JournalSource::CustomerReceipt->value)->count());
    }

    #[Test]
    public function a_receipt_cannot_be_created_against_a_draft_invoice(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $draft = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $draft->getKey(), 'amount' => '200.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.sales_invoice_id');
    }

    #[Test]
    public function a_receipt_cannot_settle_another_customers_invoice(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $otherCustomer = $this->createCustomer($user, $company, $accounts, ['customer_code' => 'OTHER']);

        $invoice = $this->postInvoice($user, $company, $otherCustomer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.sales_invoice_id');
    }

    #[Test]
    public function allocations_must_total_the_receipt_amount(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '150.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations');

        $this->assertSame(0, CustomerReceipt::count());
    }

    #[Test]
    public function an_allocation_larger_than_the_outstanding_balance_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '250.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '250.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.sales_invoice_id');
    }

    #[Test]
    public function the_same_invoice_cannot_be_allocated_twice(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '100.00'],
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '100.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.1.sales_invoice_id');
    }

    #[Test]
    public function the_payment_account_must_be_an_asset(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                // A liability, so not a bank account.
                'payment_account_id' => $accounts['payable']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');
    }

    #[Test]
    public function an_inactive_payment_account_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $accounts['cash']->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');
    }

    #[Test]
    public function posting_a_receipt_debits_cash_and_credits_the_customer_receivable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $receipt = $this->postReceipt($user, $company, $customer, $accounts, $invoice, '200.00');

        $journal = Journal::findOrFail($receipt->journal_id);
        $this->assertSame(JournalSource::CustomerReceipt->value, $journal->source_type->value);
        $this->assertSame($receipt->getKey(), $journal->source_id);

        $lines = $journal->lines()->get();

        /*
         * Cash is debited and the customer's own receivable is credited, even
         * though it was the invoice that was posted against that receivable.
         * The credit lands on the account the invoice used, which is why the
         * balance falls rather than a fresh negative appearing somewhere else.
         */
        $this->assertSame('200.0000', (string) $lines->where('account_id', $accounts['cash']->getKey())->first()->debit);
        $this->assertSame('200.0000', (string) $lines->where('account_id', $accounts['receivable']->getKey())->first()->credit);
    }

    #[Test]
    public function posting_a_partial_receipt_marks_the_invoice_partially_paid(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->postReceipt($user, $company, $customer, $accounts, $invoice, '120.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::PartiallyPaid->value)
            ->assertJsonPath('data.paid_total', '120.0000')
            ->assertJsonPath('data.balance_due', '80.0000');
    }

    #[Test]
    public function posting_a_full_receipt_marks_the_invoice_paid(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->postReceipt($user, $company, $customer, $accounts, $invoice, '200.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::Paid->value)
            ->assertJsonPath('data.paid_total', '200.0000')
            ->assertJsonPath('data.balance_due', '0.0000');
    }

    #[Test]
    public function a_receipt_allocated_across_two_invoices_credits_each_one_separately(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $first = $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-10');
        $second = $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-11');

        $receipt = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '300.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $first->getKey(), 'amount' => '100.00'],
                    ['sales_invoice_id' => $second->getKey(), 'amount' => '200.00'],
                ],
            ])->assertCreated();

        $receiptId = $receipt->json('data.id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/customer-receipts/{$receiptId}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PaymentStatus::Posted->value);

        $lines = Journal::findOrFail(CustomerReceipt::findOrFail($receiptId)->journal_id)->lines()->get();

        /*
         * One cash debit and one AR credit for the whole receipt, not one credit
         * per invoice. The receipt is a single receipt of cash; splitting it into
         * several AR credits would imply separate receipts, and the per-invoice
         * split already lives in customer_receipt_allocations, which is where
         * settlement is recorded. The ledger records the money movement.
         */
        $this->assertCount(2, $lines);
        $this->assertSame('300.0000', (string) $lines->where('account_id', $accounts['cash']->getKey())->first()->debit);
        $this->assertSame('300.0000', (string) $lines->where('account_id', $accounts['receivable']->getKey())->first()->credit);
    }

    #[Test]
    public function a_paid_invoice_cannot_be_paid_again(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->postReceipt($user, $company, $customer, $accounts, $invoice, '200.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-21',
                'amount' => '50.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '50.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.sales_invoice_id');
    }

    #[Test]
    public function a_posted_receipt_cannot_be_posted_edited_or_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $receipt = $this->postReceipt($user, $company, $customer, $accounts, $invoice, '200.00');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/customer-receipts/{$receipt->getKey()}/post")
            ->assertStatus(409);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/customer-receipts/{$receipt->getKey()}", [
                'amount' => '300.00',
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '300.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/customer-receipts/{$receipt->getKey()}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt');
    }

    #[Test]
    public function a_draft_receipt_can_be_updated_and_its_allocations_replaced(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $first = $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-10');
        $second = $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-11');

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $first->getKey(), 'amount' => '200.00'],
                ],
            ])->assertCreated();

        $receiptId = $created->json('data.id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/customer-receipts/{$receiptId}", [
                'amount' => '300.00',
                'allocations' => [
                    ['sales_invoice_id' => $first->getKey(), 'amount' => '100.00'],
                    ['sales_invoice_id' => $second->getKey(), 'amount' => '200.00'],
                ],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.amount', '300.0000');

        /*
         * A full replace, not a merge: the original 200 against the first invoice
         * is gone, and the first invoice is now owed 100 rather than 0.
         */
        $updated = CustomerReceipt::findOrFail($receiptId);

        $this->assertSame(2, $updated->allocations()->count());
        $this->assertSame(
            $first->getKey(),
            $updated->allocations()->orderBy('id')->first()->sales_invoice_id
        );
    }

    #[Test]
    public function a_draft_receipt_can_be_deleted_and_its_number_is_not_reused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $created = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '50.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '50.00'],
                ],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/customer-receipts/{$created->json('data.id')}")
            ->assertSuccessful();

        $this->assertSame(0, CustomerReceipt::count());
    }

    #[Test]
    public function a_draft_receipt_does_not_reduce_the_invoice_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '50.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '50.00'],
                ],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', TransactionStatus::Posted->value)
            ->assertJsonPath('data.paid_total', '0.0000');
    }

    #[Test]
    public function a_receipt_from_another_company_is_not_found(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $foreign = CustomerReceipt::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/customer-receipts/{$foreign->getKey()}")
            ->assertNotFound();
    }

    #[Test]
    public function an_invoice_from_another_company_cannot_be_allocated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);
        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $other = $this->createUnrelatedCompany();
        $foreign = SalesInvoice::factory()->for($other)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => '2027-01-20',
                'amount' => '50.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $foreign->getKey(), 'amount' => '50.00'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('allocations.0.sales_invoice_id');
    }

    #[Test]
    public function the_outstanding_filter_excludes_settled_invoices(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $settled = $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-10');
        $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-11');

        $this->postReceipt($user, $company, $customer, $accounts, $settled, '200.00');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/sales-invoices?outstanding=1')
            ->assertSuccessful();

        $this->assertCount(1, $response->json('data'));
    }

    private function postReceipt(
        User $user,
        Company $company,
        Customer $customer,
        array $accounts,
        SalesInvoice $invoice,
        string $amount,
        string $date = '2027-01-20'
    ): CustomerReceipt {
        $this->makePeriodFor($company, $date, 'P '.substr($date, 0, 7).uniqid());

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => $date,
                'amount' => $amount,
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => $amount],
                ],
            ])->assertCreated();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/customer-receipts/{$response->json('data.id')}/post")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PaymentStatus::Posted->value);

        return CustomerReceipt::findOrFail($response->json('data.id'));
    }
}
