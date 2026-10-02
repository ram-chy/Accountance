<?php

namespace Tests\Feature\Accounting;

use App\Enums\PermissionName;
use App\Enums\TaxType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the tax reports say, and what they refuse to say.
 *
 * The reports are built from posted documents' own line snapshots, so the setup
 * here posts real invoices and real bills rather than inserting rows directly:
 * a report that read a column nothing else populates would pass against fixtures
 * and fail against the application.
 *
 * Most of the file is about what the reports refuse to invent - drafts, other
 * tenants, dates outside the window, and the unattributed amounts a hand-entered
 * rate leaves behind. Those are the cases where a report would otherwise be
 * quietly wrong rather than loudly absent.
 */
class TaxReportTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Company $company;

    /** @var array<string, Account> */
    private array $accounts;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->createUserWithRole('Accountant');
        $this->company = $this->createCompanyFor($this->accountant);
        $this->accounts = $this->makeTransactionAccounts($this->company);
        $this->customer = $this->createCustomer($this->accountant, $this->company, $this->accounts);
        $this->supplier = $this->createSupplier($this->accountant, $this->company, $this->accounts);
    }

    #[Test]
    public function the_summary_reports_tax_collected_and_recovered(): void
    {
        $this->openPeriod('2027-01-10');

        // 200.00 net at 10% collects 20.00.
        $this->postSalesInvoice(['tax_ids' => [$this->outputTax('10.0000')->getKey()]]);

        // 300.00 net at 5% recovers 15.00.
        $this->postTaxedBill([
            'tax_ids' => [$this->inputTax('5.0000', 'VATIN5')->getKey()],
            'unit_cost' => '150.00',
        ]);

        $totals = $this->report('/api/accounting/tax-reports/summary')['totals'];

        $this->assertSame('200.0000', $totals['sales_taxable']);
        $this->assertSame('20.0000', $totals['output_tax']);
        $this->assertSame('300.0000', $totals['purchase_taxable']);
        $this->assertSame('15.0000', $totals['input_tax']);

        // Collected 20.00 against recovered 15.00 leaves 5.00 owed.
        $this->assertSame('5.0000', $totals['net_tax']);
    }

    /**
     * A report over an empty period reports zero rather than omitting itself: an
     * absent row is indistinguishable from a broken query, and zero is an answer.
     */
    #[Test]
    public function the_summary_is_zero_on_an_empty_ledger_rather_than_absent(): void
    {
        $totals = $this->report('/api/accounting/tax-reports/summary')['totals'];

        $this->assertSame('0.0000', $totals['output_tax']);
        $this->assertSame('0.0000', $totals['input_tax']);
        $this->assertSame('0.0000', $totals['net_tax']);
        $this->assertSame([], $this->report('/api/accounting/tax-reports/summary')['rows']);
    }

    /**
     * The report reads the books, not the configuration. Raising a tax's rate after
     * the fact changes what future documents are charged and must not change what a
     * closed January reported.
     */
    #[Test]
    public function a_rate_raised_after_the_fact_does_not_move_a_past_period(): void
    {
        $this->openPeriod('2027-01-10');

        $tax = $this->outputTax('10.0000');

        $this->postSalesInvoice(['tax_ids' => [$tax->getKey()]]);

        TaxRate::factory()->for($tax)->rate('25.0000')->effectiveFrom('2027-04-01')->create();

        $this->assertSame(
            '20.0000',
            $this->report('/api/accounting/tax-reports/summary')['totals']['output_tax'],
            'A rate effective in April cannot retroactively change January.'
        );
    }

    #[Test]
    public function by_tax_gives_each_tax_its_own_row(): void
    {
        $this->openPeriod('2027-01-10');

        $ten = $this->outputTax('10.0000', 'VAT10');
        $twenty = $this->outputTax('20.0000', 'VAT20');

        $this->postSalesInvoice(['tax_ids' => [$ten->getKey()]]);
        $this->postSalesInvoice(['tax_ids' => [$twenty->getKey()]]);

        $rows = collect($this->report('/api/accounting/tax-reports/by-tax')['rows'])->keyBy('tax_code');

        $this->assertSame('20.0000', $rows['VAT10']['output_tax']);
        $this->assertSame('200.0000', $rows['VAT10']['sales_taxable']);
        $this->assertSame('40.0000', $rows['VAT20']['output_tax']);

        // Both invoices are for the same 200.00 net; only the rate differs.
        $this->assertSame('200.0000', $rows['VAT20']['sales_taxable']);
    }

    /**
     * The figure an invoice line actually carries, named by the tax it came from.
     */
    #[Test]
    public function by_tax_names_the_tax_the_amount_came_from(): void
    {
        $this->openPeriod('2027-01-10');

        $tax = $this->outputTax('10.0000', 'VAT10');

        $this->postSalesInvoice(['tax_ids' => [$tax->getKey()]]);

        $row = collect($this->report('/api/accounting/tax-reports/by-tax')['rows'])->sole();

        $this->assertSame($tax->getKey(), $row['tax_id']);
        $this->assertSame('VAT10', $row['tax_code']);
        $this->assertSame(TaxType::Output->value, $row['tax_type']);
        $this->assertSame('20.0000', $row['net_tax']);
    }

    /**
     * A document that never reached the ledger has no tax to report, however
     * confident its own totals look.
     */
    #[Test]
    public function a_draft_document_is_not_reported(): void
    {
        $this->openPeriod('2027-01-10');

        $this->createDraftTaxedInvoice(['tax_ids' => [$this->outputTax('10.0000')->getKey()]]);

        $this->assertSame(
            '0.0000',
            $this->report('/api/accounting/tax-reports/summary')['totals']['output_tax'],
            'A draft has not been collected yet.'
        );
    }

    #[Test]
    public function a_document_outside_the_window_is_not_reported(): void
    {
        $this->openPeriod('2027-01-10');

        $this->postSalesInvoice(['tax_ids' => [$this->outputTax('10.0000')->getKey()]]);

        $data = $this->report('/api/accounting/tax-reports/summary?from_date=2027-02-01&to_date=2027-02-28');

        $this->assertSame('0.0000', $data['totals']['output_tax']);
        $this->assertSame('2027-02-01', $data['period']['from']);
        $this->assertSame('2027-02-28', $data['period']['to']);
    }

    #[Test]
    public function the_window_is_inclusive_at_both_ends(): void
    {
        $this->openPeriod('2027-01-10');

        $this->postSalesInvoice(['tax_ids' => [$this->outputTax('10.0000')->getKey()]]);

        $totals = $this->report('/api/accounting/tax-reports/summary?from_date=2027-01-10&to_date=2027-01-10')['totals'];

        $this->assertSame('20.0000', $totals['output_tax']);
    }

    /**
     * A line charged a hand-entered percentage names no tax. Dropping its amount
     * would leave a report whose rows no longer foot to the ledger, so it is
     * reported as unattributed and still counted in the totals.
     */
    #[Test]
    public function a_hand_entered_rate_is_reported_as_unattributed_rather_than_dropped(): void
    {
        $this->openPeriod('2027-01-10');

        $this->postSalesInvoice(['tax_rate' => '7.5']);

        $data = $this->report('/api/accounting/tax-reports/summary');

        $this->assertSame('15.0000', $data['totals']['output_tax']);
        $this->assertSame('15.0000', $data['totals']['unattributed_tax']);

        $row = collect($data['rows'])->sole();

        $this->assertNull($row['tax_id']);
        $this->assertSame('15.0000', $row['unattributed_tax']);
        $this->assertSame('200.0000', $row['sales_taxable'], 'The base stays the net, not the gross.');
    }

    /**
     * A line charging two taxes at once holds one `tax_id` column, so it cannot name
     * both. The money is still collected, so it must still appear.
     */
    #[Test]
    public function a_multi_tax_line_is_reported_as_unattributed(): void
    {
        $this->openPeriod('2027-01-10');

        $this->postSalesInvoice(['tax_ids' => [
            $this->outputTax('10.0000', 'VAT10')->getKey(),
            $this->outputTax('5.0000', 'LEVY5')->getKey(),
        ]]);

        $totals = $this->report('/api/accounting/tax-reports/summary')['totals'];

        $this->assertSame('30.0000', $totals['output_tax'], 'Both components are collected.');
        $this->assertSame('30.0000', $totals['unattributed_tax']);
    }

    /**
     * The most important property of the report: what it says must equal what the
     * documents behind it say. Without this, every other assertion in the file
     * could pass on numbers that agree with nothing.
     */
    #[Test]
    public function the_report_agrees_with_the_documents_that_produced_it(): void
    {
        $this->openPeriod('2027-01-10');

        $this->postSalesInvoice(['tax_ids' => [$this->outputTax('10.0000')->getKey()]]);
        $this->postTaxedBill(['tax_ids' => [$this->inputTax('10.0000', 'VATIN')->getKey()]]);

        $totals = $this->report('/api/accounting/tax-reports/summary')['totals'];

        $this->assertSame('20.0000', $totals['output_tax'], 'Collected must equal the invoice tax_total.');
        $this->assertSame('20.0000', $totals['input_tax'], 'Recovered must equal the bill tax_total.');
        $this->assertSame('0.0000', $totals['net_tax'], 'Equal collection and recovery nets to zero.');
    }

    #[Test]
    public function another_tenants_tax_is_not_reported(): void
    {
        $this->openPeriod('2027-01-10');

        $rival = $this->createCompanyFor($this->accountant, [], false);

        // The rival needs its own accounts, customer and open period; this
        // company's would be rejected by the request's company-scoped rules, and a
        // period is what lets the invoice reach the ledger at all.
        $rivalAccounts = $this->makeTransactionAccounts($rival);
        $rivalCustomer = $this->createCustomer($this->accountant, $rival, $rivalAccounts);
        $this->makePeriodFor($rival, '2027-01-10', 'RIVAL 2027-01');

        $rivalTax = Tax::factory()->for($rival)->create([
            'code' => 'RIVAL',
            'name' => 'Rival tax',
            'tax_type' => TaxType::Output,
        ]);

        $this->rateFor($rivalTax, '10.0000');

        $this->postSalesInvoice(
            ['tax_ids' => [$rivalTax->getKey()]],
            ['customer_id' => $rivalCustomer->getKey()],
            $rival,
            $rivalAccounts,
        );

        $this->assertSame(
            '0.0000',
            $this->report('/api/accounting/tax-reports/summary')['totals']['output_tax'],
            'Another tenant\'s collected tax is not ours to report.'
        );
    }

    /**
     * Staff may read the ledger, and Phase 10 gives them no tax access at all - so
     * the tax report is closed to them even though `/accounting/reports/*` is not.
     */
    #[Test]
    public function a_user_without_the_tax_permission_is_refused(): void
    {
        $staff = $this->createUserWithRole('Staff');

        // Staff is made a member of a real company on purpose. Without that the 403
        // would be satisfied by having no membership at all, and the test would
        // pass even if Staff had been granted the tax report permission.
        $staffCompany = $this->createCompanyFor($staff);

        $this->actingAsJwt($staff)
            ->withCompanyContext($staffCompany)
            ->getJson('/api/accounting/tax-reports/summary')
            ->assertForbidden();

        // The same authenticated user, same company context, on an endpoint with no
        // accounting gate at all: the 403 above is the tax report's own
        // authorization, not a failed context or a broken token.
        $this->actingAsJwt($staff)
            ->withCompanyContext($staffCompany)
            ->getJson('/api/auth/me')
            ->assertSuccessful();
    }

    #[Test]
    public function a_manager_with_the_tax_permission_is_allowed(): void
    {
        $manager = $this->createUserWithRole('Manager');

        // A company of the manager's own: permissions resolve through the active
        // company, so a manager who is not a member of it has none there.
        $managerCompany = $this->createCompanyFor($manager);

        $this->assertTrue($manager->can(PermissionName::TaxReportView->value));

        $this->actingAsJwt($manager)
            ->withCompanyContext($managerCompany)
            ->getJson('/api/accounting/tax-reports/by-tax')
            ->assertSuccessful();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $url): array
    {
        $response = $this->actingAsJwt($this->accountant)->withCompanyContext($this->company)->getJson($url);

        $response->assertSuccessful();

        return $response->json('data');
    }

    private function openPeriod(string $date): void
    {
        $this->makePeriodFor($this->company, $date, 'P '.substr($date, 0, 7).uniqid());
    }

    /**
     * @param  array<string, mixed>  $lineOverrides
     */
    private function createDraftTaxedInvoice(array $lineOverrides = []): void
    {
        $this->invoiceIn($this->company, $this->accounts, $lineOverrides, post: false);
    }

    /**
     * @param  array<string, mixed>  $lineOverrides
     * @param  array<string, mixed>  $documentOverrides
     * @param  array<string, Account>  $accounts
     */
    private function postSalesInvoice(array $lineOverrides = [], array $documentOverrides = [], ?Company $company = null, ?array $accounts = null): void
    {
        $this->invoiceIn($company ?? $this->company, $accounts ?? $this->accounts, $lineOverrides, $documentOverrides);
    }

    /**
     * @param  array<string, Account>  $accounts
     * @param  array<string, mixed>  $lineOverrides
     * @param  array<string, mixed>  $documentOverrides
     */
    private function invoiceIn(Company $company, array $accounts, array $lineOverrides, array $documentOverrides = [], bool $post = true): void
    {
        $response = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($company)
            ->postJson('/api/sales-invoices', array_merge([
                'customer_id' => $this->customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $accounts['tax_payable']->getKey(),
                'lines' => [array_merge([
                    'description' => 'Widget',
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ], $lineOverrides)],
            ], $documentOverrides));

        $response->assertSuccessful();

        if ($post) {
            $this->actingAsJwt($this->accountant)
                ->withCompanyContext($company)
                ->postJson('/api/sales-invoices/'.$response->json('data.id').'/post')
                ->assertSuccessful();
        }
    }

    /**
     * @param  array<string, mixed>  $lineOverrides
     */
    private function postTaxedBill(array $lineOverrides = []): void
    {
        $response = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $this->supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $this->accounts['input_tax']->getKey(),
                'lines' => [array_merge([
                    'description' => 'Widget',
                    'quantity' => '2',
                    'unit_cost' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'expense_account_id' => $this->accounts['expense']->getKey(),
                ], $lineOverrides)],
            ]);

        $response->assertSuccessful();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/purchase-bills/'.$response->json('data.id').'/post')
            ->assertSuccessful();
    }

    private function outputTax(string $rate, string $code = 'VAT'): Tax
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => $code,
            'name' => $code,
            'tax_type' => TaxType::Output,
        ]);

        $this->rateFor($tax, $rate);

        return $tax;
    }

    private function inputTax(string $rate, string $code = 'VATIN'): Tax
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => $code,
            'name' => $code,
            'tax_type' => TaxType::Input,
        ]);

        $this->rateFor($tax, $rate);

        return $tax;
    }

    private function rateFor(Tax $tax, string $rate): void
    {
        TaxRate::factory()->for($tax)->rate($rate)->effectiveFrom('2020-01-01')->create();
    }
}
