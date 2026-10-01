<?php

namespace Tests\Feature\Accounting;

use App\Enums\PeriodStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Journal;
use App\Models\PurchaseBill;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a closed accounting period refuses, across every flow that can post.
 *
 * There are six ways to reach the ledger in this system - a manual journal, a sales
 * invoice, a purchase bill, a customer receipt, a supplier payment and a cash/bank
 * transaction - and each was built in a different phase by a different service. The
 * risk is not that the rule is wrong but that one flow quietly does not have it, and
 * that is invisible until somebody posts into a closed month through that one flow.
 *
 * So the rule is asserted per flow rather than once. A single assertion against,
 * say, the journal route would be satisfied by a guard that only exists there.
 *
 * The rule itself has two halves, and the second is easy to get wrong:
 *
 *   Posting is refused      - the entry would become accounting history.
 *   Re-dating is refused    - moving accounting data into a closed date is the same
 *                             act by another route.
 *
 * What is NOT refused is creating or deleting a draft whose date sits in a closed
 * period. A draft is not accounting data yet, and forbidding it would make an
 * unwanted draft undeletable the moment its month closed. Those cases are tested
 * too, because "the guard is not too aggressive" is as much a requirement as "the
 * guard is not too permissive".
 */
class ClosedPeriodPostingTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2027-01-15';

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    private function admin(): User
    {
        return $this->createUserWithRole(RoleName::Admin);
    }

    /**
     * An open period covering self::DATE.
     */
    private function openPeriod(Company $company, string $name = 'January 2027'): void
    {
        $this->makePeriodFor($company, self::DATE, $name);
    }

    /**
     * Close every period of this company, so any posting date in it is refused.
     *
     * Going through the API rather than forcing the status is deliberate: it means
     * each test starts from a state the system itself can produce. A close needs an
     * Admin, so it is a separate actor from the Accountant doing the posting.
     */
    private function closeEveryPeriod(Company $company): void
    {
        /*
         * Only Admin holds accounting.periods.close, so closing needs a second
         * actor. It has to be a *member* of this company: the company.context
         * middleware resolves the header against the caller's memberships, and a
         * fresh Admin who was never attached has no context at all. addMemberTo
         * rather than createCompanyFor, so this Admin joins the company under test
         * instead of creating a second one with its own calendar.
         */
        $closer = $this->addMemberTo($company, $this->admin());

        $periods = AccountingPeriod::query()->where('company_id', $company->getKey())->get();

        $this->assertNotEmpty($periods, 'The test needs at least one period to close.');

        foreach ($periods as $period) {
            $this->actingAsJwt($closer)
                ->withCompanyContext($company)
                ->postJson("/api/accounting/periods/{$period->getKey()}/close")
                ->assertSuccessful();
        }
    }

    /**
     * @return array<string, Account>
     */
    private function chart(Company $company): array
    {
        return [
            'cash' => Account::factory()->for($company)->cash()->create(['code' => '1010', 'name' => 'Cash']),
            'bank' => Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']),
            'expense' => Account::factory()->for($company)->expense()->create(['code' => '5100', 'name' => 'Bank Charges']),
            'capital' => Account::factory()->for($company)->equity()->create(['code' => '3100', 'name' => 'Share Capital']),
        ];
    }

    /**
     * A draft cash/bank deposit, created through the API.
     *
     * @param  array<string, Account>  $chart
     */
    private function cashBankDraft(User $user, Company $company, array $chart, string $date): CashBankTransaction
    {
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/deposits', [
                'transaction_date' => $date,
                'amount' => '400.0000',
                'source_account_id' => $chart['capital']->getKey(),
                'destination_account_id' => $chart['bank']->getKey(),
            ]);

        $response->assertCreated();

        return CashBankTransaction::query()->whereKey($response->json('data.id'))->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Posting into a closed period
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_journal_cannot_post_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->openPeriod($company);
        $this->closeEveryPeriod($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', self::DATE);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString('January 2027', $this->responseErrors($response)['journal_date']);
        $this->assertSame('DRAFT', $journal->refresh()->status->value);
    }

    #[Test]
    public function a_sales_invoice_cannot_post_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->openPeriod($company);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $this->closeEveryPeriod($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString('January 2027', $this->responseErrors($response)['invoice_date']);
        $this->assertSame('DRAFT', $invoice->refresh()->status->value);
    }

    #[Test]
    public function a_purchase_bill_cannot_post_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->openPeriod($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => self::DATE,
                'due_date' => '2027-02-15',
                'lines' => [
                    [
                        'description' => 'Materials',
                        'quantity' => '1',
                        'unit_cost' => '500.00',
                        'discount' => '0',
                        'tax_rate' => '0',
                        'expense_account_id' => $accounts['expense']->getKey(),
                    ],
                ],
            ]);
        $response->assertCreated();

        $bill = PurchaseBill::findOrFail($response->json('data.id'));

        $this->closeEveryPeriod($company);

        $postResponse = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/purchase-bills/{$bill->getKey()}/post");

        $postResponse->assertStatus(422);
        $this->assertStringContainsString('January 2027', $this->responseErrors($postResponse)['bill_date']);
        $this->assertSame('DRAFT', $bill->refresh()->status->value);
    }

    #[Test]
    public function a_customer_receipt_cannot_post_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->openPeriod($company);

        // The invoice has to be posted while January is still open, because a
        // receipt may only settle a posted document.
        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertSuccessful();

        $receiptResponse = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/customer-receipts', [
                'customer_id' => $customer->getKey(),
                'receipt_date' => self::DATE,
                'amount' => '200.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['sales_invoice_id' => $invoice->getKey(), 'amount' => '200.00'],
                ],
            ]);
        $receiptResponse->assertCreated();

        $receipt = CustomerReceipt::findOrFail($receiptResponse->json('data.id'));

        $this->closeEveryPeriod($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/customer-receipts/{$receipt->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString('January 2027', $this->responseErrors($response)['receipt_date']);
        $this->assertSame('DRAFT', $receipt->refresh()->status->value);
    }

    #[Test]
    public function a_supplier_payment_cannot_post_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->openPeriod($company);

        $billResponse = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => self::DATE,
                'due_date' => '2027-02-15',
                'lines' => [
                    [
                        'description' => 'Materials',
                        'quantity' => '1',
                        'unit_cost' => '500.00',
                        'discount' => '0',
                        'tax_rate' => '0',
                        'expense_account_id' => $accounts['expense']->getKey(),
                    ],
                ],
            ]);
        $billResponse->assertCreated();

        $bill = PurchaseBill::findOrFail($billResponse->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/purchase-bills/{$bill->getKey()}/post")
            ->assertSuccessful();

        $paymentResponse = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => self::DATE,
                'amount' => '500.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '500.00'],
                ],
            ]);
        $paymentResponse->assertCreated();

        $payment = SupplierPayment::findOrFail($paymentResponse->json('data.id'));

        $this->closeEveryPeriod($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/supplier-payments/{$payment->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString('January 2027', $this->responseErrors($response)['payment_date']);
        $this->assertSame('DRAFT', $payment->refresh()->status->value);
    }

    #[Test]
    public function a_cash_bank_transaction_cannot_post_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $chart = $this->chart($company);

        $this->openPeriod($company);

        $transaction = $this->cashBankDraft($user, $company, $chart, self::DATE);

        $this->closeEveryPeriod($company);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post");

        $response->assertStatus(422);
        $this->assertStringContainsString('January 2027', $this->responseErrors($response)['transaction_date']);
        $this->assertSame('DRAFT', $transaction->refresh()->status->value);
        $this->assertNull($transaction->journal_id);
    }

    /*
    |--------------------------------------------------------------------------
    | Re-dating a draft into a closed period
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_journal_draft_cannot_be_moved_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, '2027-03-15', 'March 2027', closed: true);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', '2027-02-15');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", ['journal_date' => '2027-03-10']);

        $response->assertStatus(422);
        $this->assertStringContainsString('March 2027', $this->responseErrors($response)['journal_date']);
        $this->assertSame('2027-02-15', $journal->refresh()->journal_date->toDateString());
    }

    #[Test]
    public function a_sales_invoice_draft_cannot_be_moved_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->makePeriodFor($company, '2027-03-15', 'March 2027', closed: true);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/sales-invoices/{$invoice->getKey()}", [
                'invoice_date' => '2027-03-10',
                'due_date' => '2027-04-10',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('March 2027', $this->responseErrors($response)['invoice_date']);
        $this->assertSame('2027-01-10', $invoice->refresh()->invoice_date->toDateString());
    }

    #[Test]
    public function a_purchase_bill_draft_cannot_be_moved_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->makePeriodFor($company, '2027-03-15', 'March 2027', closed: true);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-02-15',
                'due_date' => '2027-03-17',
                'lines' => [
                    [
                        'description' => 'Materials',
                        'quantity' => '1',
                        'unit_cost' => '500.00',
                        'discount' => '0',
                        'tax_rate' => '0',
                        'expense_account_id' => $accounts['expense']->getKey(),
                    ],
                ],
            ]);
        $response->assertCreated();

        $bill = PurchaseBill::findOrFail($response->json('data.id'));

        $updateResponse = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/purchase-bills/{$bill->getKey()}", ['bill_date' => '2027-03-10']);

        $updateResponse->assertStatus(422);
        $this->assertStringContainsString('March 2027', $this->responseErrors($updateResponse)['bill_date']);
        $this->assertSame('2027-02-15', $bill->refresh()->bill_date->toDateString());
    }

    #[Test]
    public function a_customer_receipt_draft_cannot_be_moved_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->openPeriod($company);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertSuccessful();

        $receiptResponse = $this->actingAsJwt($user)
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
        $receiptResponse->assertCreated();

        $receipt = CustomerReceipt::findOrFail($receiptResponse->json('data.id'));

        // Close February, then try to re-date the receipt into it.
        $this->makePeriodFor($company, '2027-02-15', 'February 2027', closed: true);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/customer-receipts/{$receipt->getKey()}", ['receipt_date' => '2027-02-10']);

        $response->assertStatus(422);
        $this->assertStringContainsString('February 2027', $this->responseErrors($response)['receipt_date']);
        $this->assertSame('2027-01-20', $receipt->refresh()->receipt_date->toDateString());
    }

    #[Test]
    public function a_supplier_payment_draft_cannot_be_moved_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $accounts = $this->makeTransactionAccounts($company);
        $supplier = $this->createSupplier($user, $company, $accounts);

        $this->openPeriod($company);

        $billResponse = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [
                    [
                        'description' => 'Materials',
                        'quantity' => '1',
                        'unit_cost' => '500.00',
                        'discount' => '0',
                        'tax_rate' => '0',
                        'expense_account_id' => $accounts['expense']->getKey(),
                    ],
                ],
            ]);
        $billResponse->assertCreated();

        $bill = PurchaseBill::findOrFail($billResponse->json('data.id'));

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/purchase-bills/{$bill->getKey()}/post")
            ->assertSuccessful();

        $paymentResponse = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/supplier-payments', [
                'supplier_id' => $supplier->getKey(),
                'payment_date' => '2027-01-20',
                'amount' => '500.00',
                'payment_account_id' => $accounts['cash']->getKey(),
                'allocations' => [
                    ['purchase_bill_id' => $bill->getKey(), 'amount' => '500.00'],
                ],
            ]);
        $paymentResponse->assertCreated();

        $payment = SupplierPayment::findOrFail($paymentResponse->json('data.id'));

        $this->makePeriodFor($company, '2027-02-15', 'February 2027', closed: true);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/supplier-payments/{$payment->getKey()}", ['payment_date' => '2027-02-10']);

        $response->assertStatus(422);
        $this->assertStringContainsString('February 2027', $this->responseErrors($response)['payment_date']);
        $this->assertSame('2027-01-20', $payment->refresh()->payment_date->toDateString());
    }

    #[Test]
    public function a_cash_bank_draft_cannot_be_moved_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $chart = $this->chart($company);

        $transaction = $this->cashBankDraft($user, $company, $chart, '2027-02-10');

        $this->makePeriodFor($company, '2027-03-15', 'March 2027', closed: true);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-transactions/{$transaction->getKey()}", ['transaction_date' => '2027-03-10']);

        $response->assertStatus(422);
        $this->assertStringContainsString('March 2027', $this->responseErrors($response)['transaction_date']);
        $this->assertSame('2027-02-10', $transaction->refresh()->transaction_date->toDateString());
    }

    /*
    |--------------------------------------------------------------------------
    | What the guard must NOT refuse
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_draft_can_still_be_created_in_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, self::DATE, 'January 2027', closed: true);

        // A draft is not accounting data. Refusing to record one would mean a
        // document typed after the month closed could not be saved at all, and the
        // user would have to keep it on paper until somebody reopened the period.
        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', self::DATE);

        $this->assertSame('DRAFT', $journal->status->value);
    }

    #[Test]
    public function a_draft_in_a_closed_period_can_still_have_its_non_date_fields_edited(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, self::DATE, 'January 2027', closed: true);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', self::DATE);

        // The guard is on the date, not on the record. Blocking this would make a
        // typo in the description unfixable except by delete-and-recreate, which is
        // a harsher rule than the accounting one and not what closing is for.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/journals/{$journal->getKey()}", ['description' => 'Corrected wording'])
            ->assertSuccessful()
            ->assertJsonPath('data.description', 'Corrected wording');

        $this->assertSame('2027-01-15', $journal->refresh()->journal_date->toDateString());
    }

    #[Test]
    public function a_draft_in_a_closed_period_can_still_be_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->makePeriodFor($company, self::DATE, 'January 2027', closed: true);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', self::DATE);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/journals/{$journal->getKey()}")
            ->assertSuccessful();

        // A draft nobody ever posted holds no accounting history, so removing it
        // removes nothing that was ever true. Making it undeletable would leave the
        // user unable to clear an entry they decided against.
        $this->assertNull(Journal::query()->find($journal->getKey()));
    }

    #[Test]
    public function closing_a_period_does_not_disturb_an_already_posted_journal(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        [$cash, $revenue] = $this->makeCashAndRevenueAccounts($company);

        $this->openPeriod($company);

        $journal = $this->createDraftJournal($user, $company, $cash, $revenue, '100.00', self::DATE);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/journals/{$journal->getKey()}/post")
            ->assertSuccessful();

        $linesBefore = $journal->lines()->count();

        $this->closeEveryPeriod($company);

        // Closing stops new postings; it never reaches back and edits what was
        // already posted. The entries were legitimate when made.
        $this->assertSame('POSTED', $journal->refresh()->status->value);
        $this->assertSame($linesBefore, $journal->lines()->count());
    }

    #[Test]
    public function a_period_of_one_company_being_closed_does_not_block_another(): void
    {
        $user = $this->accountant();
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createCompanyFor($user, isDefault: false);

        [$cashA, $revenueA] = $this->makeCashAndRevenueAccounts($companyA);
        [$cashB, $revenueB] = $this->makeCashAndRevenueAccounts($companyB);

        $this->openPeriod($companyA);
        $this->openPeriod($companyB);

        $journalB = $this->createDraftJournal($user, $companyB, $cashB, $revenueB, '100.00', self::DATE);

        $this->closeEveryPeriod($companyA);

        // The guard is company-scoped. A closed month in company A says nothing
        // about company B's identical calendar, and treating it as global would make
        // one company's bookkeeping mistake another company's outage.
        $this->actingAsJwt($user)
            ->withCompanyContext($companyB)
            ->postJson("/api/journals/{$journalB->getKey()}/post")
            ->assertSuccessful();

        $this->assertSame('POSTED', $journalB->refresh()->status->value);
    }

    #[Test]
    public function a_closed_period_reports_that_it_does_not_accept_postings(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $period = $this->makePeriodFor($company, self::DATE, 'January 2027');

        $this->closeEveryPeriod($company);

        // The resource reports the same answer the posting boundary enforces, so a
        // client is never told a date is postable and then refused.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/accounting/periods/{$period->getKey()}")
            ->assertSuccessful()
            ->assertJsonPath('data.status', PeriodStatus::Closed->value)
            ->assertJsonPath('data.accepts_postings', false);
    }
}
