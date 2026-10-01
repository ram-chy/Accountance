<?php

namespace Tests\Feature\Transactions;

use App\Enums\JournalSource;
use App\Enums\RoleName;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sales invoice drafting, calculation and posting.
 *
 * Three groups of questions, in the order they matter:
 *
 *   1. Arithmetic. Totals are computed, never accepted, and the test asserts the
 *      exact strings - "480.00" rather than 480, because a rounding difference
 *      that a float comparison would tolerate is exactly the kind that becomes a
 *      wrong ledger.
 *   2. The ledger. Posting must produce a balanced entry with the right accounts
 *      and the right source pointer, and must be the only thing that can.
 *   3. Isolation. Nothing here accepts a company id, and nothing reads across
 *      tenants.
 */
class SalesInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function a_draft_invoice_is_created_with_server_computed_totals(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $accounts['tax_payable']->getKey(),
                'lines' => [[
                    'description' => 'Widget',
                    'quantity' => '3',
                    'unit_price' => '100.00',
                    'discount' => '50.00',
                    'tax_rate' => '20',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ]);

        /*
         * 3 x 100 = 300.00 gross, less 50.00 discount = 250.00 net, plus 20% tax
         * on the net = 50.00, giving 300.00 due.
         *
         * The tax is computed on the discounted amount, not the gross. Charging
         * tax on a discount the customer was given is a real-world error that
         * produces a genuinely wrong number, and it is invisible to a test that
         * only checks the total.
         */
        $response->assertCreated()
            ->assertJsonPath('data.subtotal', '300.0000')
            ->assertJsonPath('data.discount_total', '50.0000')
            ->assertJsonPath('data.tax_total', '50.0000')
            ->assertJsonPath('data.grand_total', '300.0000')
            ->assertJsonPath('data.status', TransactionStatus::Draft->value)
            ->assertJsonPath('data.invoice_number', 'INV-000001');
    }

    #[Test]
    public function client_supplied_totals_are_ignored_rather_than_trusted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'grand_total' => '1.00',
                'subtotal' => '1.00',
                'tax_total' => '0.00',
                'status' => TransactionStatus::Posted->value,
                'invoice_number' => 'HAND-WRITTEN',
                'lines' => [[
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ]);

        /*
         * The request is accepted, not rejected: the fields are not in the rules,
         * so they never reach the service. What matters is that the stored row
         * carries the computed 200.00 and the server's own DRAFT status and
         * sequence number. A 422 here would also be defensible, but silently
         * ignoring is what the code does and the test documents that.
         */
        $response->assertCreated()
            ->assertJsonPath('data.grand_total', '200.0000')
            ->assertJsonPath('data.status', TransactionStatus::Draft->value)
            ->assertJsonPath('data.invoice_number', 'INV-000001');

        $this->assertDatabaseMissing('sales_invoices', ['invoice_number' => 'HAND-WRITTEN']);
    }

    #[Test]
    public function tax_rounding_is_half_up_per_line_not_on_the_rounded_total(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        /*
         * Three lines of 0.0333 at 10%.
         *
         * Per line, which is what the journal credits: each is 0.00333 rounded
         * half-up to the stored scale of 4 -> 0.0033, so 0.0099 in total.
         *
         * On the combined 0.0999 the tax would be 0.00999, which rounds to
         * 0.0100 - a different number. The two methods disagree, so this test
         * pins which one is used: the per-line figure, because a total-based tax
         * would not be the sum of the amounts actually written to the ledger, and
         * the invoice total would then disagree with the journal by 0.0001.
         */
        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $accounts['tax_payable']->getKey(),
                'lines' => [
                    [
                        'quantity' => '1', 'unit_price' => '0.0333', 'discount' => '0', 'tax_rate' => '10',
                        'revenue_account_id' => $accounts['revenue']->getKey(),
                    ],
                    [
                        'quantity' => '1', 'unit_price' => '0.0333', 'discount' => '0', 'tax_rate' => '10',
                        'revenue_account_id' => $accounts['revenue']->getKey(),
                    ],
                    [
                        'quantity' => '1', 'unit_price' => '0.0333', 'discount' => '0', 'tax_rate' => '10',
                        'revenue_account_id' => $accounts['revenue']->getKey(),
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', '0.0999')
            ->assertJsonPath('data.tax_total', '0.0099')
            ->assertJsonPath('data.grand_total', '0.1098');
    }

    #[Test]
    public function a_taxed_invoice_without_a_tax_account_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        /*
         * The error has to arrive now rather than at posting time. A user who has
         * built a draft is told why before they have committed to it, instead of
         * discovering it after a failed post with no obvious cause.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_price' => '100.00', 'discount' => '0', 'tax_rate' => '20',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_account_id');
    }

    #[Test]
    public function an_invoice_with_no_lines_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    }

    #[Test]
    public function a_revenue_account_that_is_not_revenue_is_refused_and_names_the_line(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [
                    [
                        'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                        'revenue_account_id' => $accounts['revenue']->getKey(),
                    ],
                    [
                        'quantity' => '1', 'unit_price' => '20.00', 'discount' => '0', 'tax_rate' => '0',
                        // An expense account on a sales line: a real account in this
                        // company, the wrong side of the entry.
                        'revenue_account_id' => $accounts['expense']->getKey(),
                    ],
                ],
            ])
            ->assertUnprocessable()
            // The key names the offending line, not just the field.
            ->assertJsonValidationErrors('lines.1.revenue_account_id');
    }

    #[Test]
    public function every_bad_line_account_is_reported_at_once(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [
                    [
                        'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                        'revenue_account_id' => $accounts['expense']->getKey(),
                    ],
                    [
                        'quantity' => '1', 'unit_price' => '20.00', 'discount' => '0', 'tax_rate' => '0',
                        'revenue_account_id' => $accounts['expense']->getKey(),
                    ],
                ],
            ]);

        $response->assertUnprocessable();

        /*
         * Both lines are reported in a single 422. Validating line by line would
         * cost the user a round trip per mistake, and for a 40-line invoice with
         * a bad default account that is 40 submissions to discover one problem.
         */
        $errors = $response->json('errors');

        $this->assertArrayHasKey('lines.0.revenue_account_id', $errors);
        $this->assertArrayHasKey('lines.1.revenue_account_id', $errors);
    }

    #[Test]
    public function a_due_date_before_the_invoice_date_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-02-10',
                'due_date' => '2027-01-10',
                'lines' => [[
                    'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('due_date');
    }

    #[Test]
    public function a_zero_or_negative_quantity_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '0', 'unit_price' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.quantity');
    }

    #[Test]
    public function an_inactive_customer_cannot_be_invoiced(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $customer->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    #[Test]
    public function a_customer_from_another_company_cannot_be_invoiced(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();
        $otherAccounts = $this->makeTransactionAccounts($other);

        $foreignCustomer = Customer::factory()->for($other)->create([
            'receivable_account_id' => $otherAccounts['receivable']->getKey(),
        ]);

        $accounts = $this->makeTransactionAccounts($company);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $foreignCustomer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '1', 'unit_price' => '10.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    #[Test]
    public function posting_an_invoice_writes_a_balanced_entry_against_the_customers_receivable(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, overrides: [
            'lines' => [[
                'quantity' => '2', 'unit_price' => '150.00', 'discount' => '0', 'tax_rate' => '0',
                'revenue_account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $this->assertSame(TransactionStatus::Posted, $invoice->status);

        $journal = Journal::findOrFail($invoice->journal_id);

        // source_type is cast to the enum, so compare the backing value.
        $this->assertSame(JournalSource::SalesInvoice->value, $journal->source_type->value);
        $this->assertSame($invoice->getKey(), $journal->source_id);

        $lines = $journal->lines()->get();

        $debit = $lines->where('account_id', $accounts['receivable']->getKey())->first();
        $credit = $lines->where('account_id', $accounts['revenue']->getKey())->first();

        $this->assertNotNull($debit, 'The receivable account must be debited.');
        $this->assertNotNull($credit, 'The revenue account must be credited.');
        $this->assertSame('300.0000', (string) $debit->debit);
        $this->assertSame('300.0000', (string) $credit->credit);
    }

    #[Test]
    public function tax_is_credited_to_a_liability_account_separately_from_revenue(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, overrides: [
            'tax_account_id' => $accounts['tax_payable']->getKey(),
            'lines' => [[
                'quantity' => '1', 'unit_price' => '100.00', 'discount' => '0', 'tax_rate' => '20',
                'revenue_account_id' => $accounts['revenue']->getKey(),
            ]],
        ]);

        $journal = Journal::findOrFail($invoice->journal_id);
        $lines = $journal->lines()->get();

        /*
         * Revenue is credited with the NET amount, 100.00, and the tax with
         * 20.00. Crediting revenue with the 120.00 gross would report 20% more
         * revenue than was earned - the single most common way a tax invoice
         * produces a wrong income figure, and invisible in a balanced entry.
         */
        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['revenue']->getKey())->first()->credit);
        $this->assertSame('20.0000', (string) $lines->where('account_id', $accounts['tax_payable']->getKey())->first()->credit);
        $this->assertSame('120.0000', (string) $lines->where('account_id', $accounts['receivable']->getKey())->first()->debit);
    }

    #[Test]
    public function two_revenue_accounts_produce_two_separate_credits(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);

        $secondRevenue = Account::factory()->for($company)->revenue()->create([
            'code' => '4100', 'name' => 'Service Revenue',
        ]);

        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts, overrides: [
            'lines' => [
                [
                    'quantity' => '1', 'unit_price' => '100.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ],
                [
                    'quantity' => '1', 'unit_price' => '250.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $secondRevenue->getKey(),
                ],
            ],
        ]);

        $lines = Journal::findOrFail($invoice->journal_id)->lines()->get();

        /*
         * Revenue is credited per account so the ledger can answer "how much of
         * this invoice was product X" without a report re-reading the document.
         * Two lines on the same account would be merged; two accounts must not.
         */
        $this->assertSame('100.0000', (string) $lines->where('account_id', $accounts['revenue']->getKey())->first()->credit);
        $this->assertSame('250.0000', (string) $lines->where('account_id', $secondRevenue->getKey())->first()->credit);
    }

    #[Test]
    public function an_invoice_cannot_be_posted_twice(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts);
        $journalId = $invoice->journal_id;

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertStatus(409);

        /*
         * One journal, not two. A second post that succeeded would double the
         * receivable and leave two entries pointing at one document, and the
         * trial balance would still balance - so the duplication would only be
         * visible by counting source pointers.
         */
        $this->assertSame(1, Journal::where('source_type', JournalSource::SalesInvoice->value)
            ->where('source_id', $invoice->getKey())
            ->count());

        $this->assertSame($journalId, $invoice->refresh()->journal_id);
    }

    #[Test]
    public function a_posted_invoice_cannot_be_edited_or_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/sales-invoices/{$invoice->getKey()}", ['notes' => 'Tampered'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice');

        $this->assertNull($invoice->refresh()->notes, 'A refused edit must not have been partially applied.');
    }

    #[Test]
    public function a_draft_invoice_can_be_deleted_and_its_number_is_not_reused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $first = $this->createDraftInvoice($user, $company, $customer, $accounts);
        $this->assertSame('INV-000001', $first->invoice_number);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/sales-invoices/{$first->getKey()}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('sales_invoices', ['id' => $first->getKey()]);

        /*
         * The next invoice is INV-000002, not a reuse of 000001. A number that
         * was issued to a document which once existed must not be reissued -
         * otherwise a printed invoice and its replacement would be
         * indistinguishable, and any reference to the first would now resolve to
         * the second.
         */
        $second = $this->createDraftInvoice($user, $company, $customer, $accounts);
        $this->assertSame('INV-000002', $second->invoice_number);
    }

    #[Test]
    public function an_invoice_cannot_be_posted_into_a_closed_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->makePeriodFor($company, '2027-01-10', 'Closed January', closed: true);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertUnprocessable();

        $this->assertSame(TransactionStatus::Draft, $invoice->refresh()->status);
        $this->assertNull($invoice->journal_id, 'A refused post must leave no journal behind.');
    }

    #[Test]
    public function an_invoice_cannot_be_posted_with_no_open_period(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertUnprocessable();

        $this->assertSame(TransactionStatus::Draft, $invoice->refresh()->status);
    }

    #[Test]
    public function a_customer_deactivated_after_drafting_blocks_the_post(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $customer->forceFill(['is_active' => false])->save();

        /*
         * Re-checked at posting, not trusted from draft time. A draft is not an
         * accounting fact, so there is nothing to preserve by honouring it - the
         * user can reactivate or move the invoice. Re-checking at draft time
         * alone would let this through.
         */
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');
    }

    #[Test]
    public function a_revenue_account_deactivated_after_drafting_blocks_the_post(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $accounts['revenue']->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('revenue_account_id');
    }

    #[Test]
    public function a_revenue_account_stolen_from_another_company_blocks_the_post(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();

        $accounts = $this->makeTransactionAccounts($company);
        $foreignRevenue = Account::factory()->for($other)->revenue()->create();

        $customer = $this->createCustomer($user, $company, $accounts);

        // Written directly, bypassing the request's company-scoped exists() rule,
        // to prove the posting service re-validates rather than trusting the row.
        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);
        $invoice->lines()->update(['revenue_account_id' => $foreignRevenue->getKey()]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('revenue_account_id');
    }

    #[Test]
    public function an_update_replaces_the_lines_and_recomputes_the_totals(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts, [
            'lines' => [
                ['quantity' => '1', 'unit_price' => '100.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey()],
                ['quantity' => '1', 'unit_price' => '100.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey()],
            ],
        ]);

        $this->assertSame('200.0000', (string) $invoice->grand_total);
        $this->assertCount(2, $invoice->lines()->get());

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/sales-invoices/{$invoice->getKey()}", [
                'lines' => [[
                    'quantity' => '1', 'unit_price' => '75.00', 'discount' => '0', 'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.grand_total', '75.0000');

        $fresh = $invoice->refresh();

        /*
         * One line, not two. Lines are replaced wholesale rather than merged: a
         * diff could not distinguish "remove line 2" from "line 2 was never
         * meant to be sent", and a document whose totals derive from its lines
         * has to be saved whole or not at all.
         */
        $this->assertCount(1, $fresh->lines()->get());
        $this->assertSame('75.0000', (string) $fresh->grand_total);
    }

    #[Test]
    public function settlement_figures_are_derived_not_stored(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $invoice = $this->postInvoice($user, $company, $customer, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertSuccessful();

        /*
         * paid_total 0.00 and balance_due 200.00, with no allocation behind them
         * yet. The columns do not exist; these are summed on read. A stored
         * counter would have to be decremented when a receipt is deleted, and a
         * missed decrement is a permanently wrong balance with nothing to notice
         * it.
         */
        $response->assertJsonPath('data.paid_total', '0.0000')
            ->assertJsonPath('data.balance_due', '200.0000')
            ->assertJsonPath('data.is_overdue', false);

        $this->assertFalse(
            Schema::hasColumn('sales_invoices', 'paid_total'),
            'paid_total must be derived, not stored.',
        );
        $this->assertFalse(
            Schema::hasColumn('sales_invoices', 'balance_due'),
            'balance_due must be derived, not stored.',
        );
    }

    #[Test]
    public function the_outstanding_filter_selects_only_invoices_with_a_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-10');
        $this->postInvoice($user, $company, $customer, $accounts, date: '2027-01-20');

        // A draft must never appear in an outstanding list: it has no journal,
        // so its balance is not money anyone owes.
        $this->createDraftInvoice($user, $company, $customer, $accounts);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/sales-invoices?outstanding=1')
            ->assertSuccessful();

        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function the_invoice_number_prefix_comes_from_company_settings(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($user, $company, $accounts);

        $company->settings()->update(['invoice_number_prefix' => 'ACME-']);

        $invoice = $this->createDraftInvoice($user, $company, $customer, $accounts);

        $this->assertSame('ACME-000001', $invoice->invoice_number);
    }

    #[Test]
    public function invoice_numbers_are_sequential_per_company(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $companyA = $this->createCompanyFor($user);
        $companyB = $this->createUnrelatedCompany();
        $this->addMemberTo($companyB, $user);

        $accountsA = $this->makeTransactionAccounts($companyA);
        $accountsB = $this->makeTransactionAccounts($companyB);

        $customerA = $this->createCustomer($user, $companyA, $accountsA);
        $customerB = $this->createCustomer($user, $companyB, $accountsB);

        $this->createDraftInvoice($user, $companyA, $customerA, $accountsA);
        $second = $this->createDraftInvoice($user, $companyA, $customerA, $accountsA);

        $otherCompanyFirst = $this->createDraftInvoice($user, $companyB, $customerB, $accountsB);

        /*
         * The sequence is per company, not global. A single global counter would
         * make company B's first invoice read 000003 because of two documents it
         * has never heard of, which is confusing in a document whose whole point
         * is to be a readable reference.
         */
        $this->assertSame('INV-000002', $second->invoice_number);
        $this->assertSame('INV-000001', $otherCompanyFirst->invoice_number);
    }

    #[Test]
    public function an_invoice_from_another_company_is_not_found(): void
    {
        $user = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($user);
        $other = $this->createUnrelatedCompany();
        $otherAccounts = $this->makeTransactionAccounts($other);

        $foreignCustomer = Customer::factory()->for($other)->create([
            'receivable_account_id' => $otherAccounts['receivable']->getKey(),
        ]);

        $foreignInvoice = SalesInvoice::factory()->for($other)->for($foreignCustomer)->create();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$foreignInvoice->getKey()}")
            ->assertNotFound();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$foreignInvoice->getKey()}/post")
            ->assertNotFound();
    }

    #[Test]
    public function a_manager_may_view_but_not_post_an_invoice(): void
    {
        $admin = $this->createUserWithRole(RoleName::Admin);
        $company = $this->createCompanyFor($admin);
        $accounts = $this->makeTransactionAccounts($company);
        $customer = $this->createCustomer($admin, $company, $accounts);

        $this->makePeriodFor($company, '2027-01-10', 'P 2027-01');
        $invoice = $this->createDraftInvoice($admin, $company, $customer, $accounts);

        $manager = $this->createUserWithRole(RoleName::Manager);
        $this->addMemberTo($company, $manager);

        /*
         * View is a read and is granted to Manager. Post is the irreversible act
         * and is not - which is the whole point of separating the two grants
         * rather than letting update imply post.
         */
        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->getJson("/api/sales-invoices/{$invoice->getKey()}")
            ->assertSuccessful();

        $this->actingAsJwt($manager)
            ->withCompanyContext($company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post")
            ->assertForbidden();

        $this->assertSame(TransactionStatus::Draft, $invoice->refresh()->status);
    }
}
