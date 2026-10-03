<?php

namespace Tests\Feature\Accounting;

use App\Enums\NoteType;
use App\Enums\RoleName;
use App\Enums\TaxType;
use App\Models\Account;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What notes do to the tax reports.
 *
 * Separate from TaxReportTest because the question here is different. That file
 * asks what a tax report says about invoices and bills; this one asks whether a
 * later document can take tax back out of a period those documents already
 * reported - and the answer has to be yes, because an undeclared return is a tax
 * liability the company has not accounted for.
 *
 * Real notes are posted rather than rows inserted, for the same reason the rest
 * of the tax coverage does it: a report that read a column the posting path is
 * responsible for filling would pass against a fixture and fail against the
 * application.
 */
class TaxReportNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Company $company;

    /** @var array<string, Account> */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->createUserWithRole(RoleName::Accountant);
        $this->company = $this->createCompanyFor($this->accountant);
        $this->accounts = $this->makeTransactionAccounts($this->company);
    }

    #[Test]
    public function a_sales_credit_note_takes_output_tax_back_out_of_the_period(): void
    {
        $vat = $this->outputTax('20.0000');

        // 100.00 net at 20% reports 20.00 of output tax in February.
        $invoice = $this->taxedInvoice($vat);

        $this->assertSame('20.0000', $this->totals()['output_tax']);

        // Half of it comes back in March, so March reports -10.00 on its own...
        $this->publishSalesNote($invoice, NoteType::SalesCreditNote, $vat, '50.00', '2027-03-10');

        $march = $this->totals('2027-03-01', '2027-03-31');

        $this->assertSame('-10.0000', $march['output_tax']);
        $this->assertSame('-50.0000', $march['sales_taxable']);

        /*
         * ...and the year to date reports what was actually collected, 20.00 less
         * 10.00. The two figures answer different questions, and asserting only one
         * of them would leave a report that got the window right and the total
         * wrong - or the reverse - looking correct.
         */
        $this->assertSame('10.0000', $this->totals()['output_tax']);
        $this->assertSame('50.0000', $this->totals()['sales_taxable']);
    }

    #[Test]
    public function a_sales_debit_note_adds_output_tax_to_the_period(): void
    {
        $vat = $this->outputTax('20.0000');

        $invoice = $this->taxedInvoice($vat);

        $this->publishSalesNote($invoice, NoteType::SalesDebitNote, $vat, '100.00', '2027-03-10');

        $totals = $this->totals('2027-03-01', '2027-03-31');

        // A supplementary charge is a sale, and is taxed as one.
        $this->assertSame('20.0000', $totals['output_tax']);
        $this->assertSame('100.0000', $totals['sales_taxable']);
    }

    #[Test]
    public function a_purchase_credit_note_takes_input_tax_back_out_of_the_period(): void
    {
        $vat = $this->inputTax('20.0000');

        $billId = $this->taxedBill($vat);

        $this->assertSame('20.0000', $this->totals()['input_tax']);

        // Half of it comes back in March, so March reports -10.00 on its own...
        $this->publishPurchaseNote($billId, NoteType::PurchaseCreditNote, $vat, '50.00', '2027-03-10');

        $march = $this->totals('2027-03-01', '2027-03-31');

        $this->assertSame('-10.0000', $march['input_tax']);
        $this->assertSame('-50.0000', $march['purchase_taxable']);

        // ...and the year to date is the 10.00 actually recovered.
        $this->assertSame('10.0000', $this->totals()['input_tax']);
        $this->assertSame('50.0000', $this->totals()['purchase_taxable']);
    }

    #[Test]
    public function a_purchase_debit_note_adds_input_tax_to_the_period(): void
    {
        $vat = $this->inputTax('20.0000');

        $billId = $this->taxedBill($vat);

        $this->publishPurchaseNote($billId, NoteType::PurchaseDebitNote, $vat, '100.00', '2027-03-10');

        $totals = $this->totals('2027-03-01', '2027-03-31');

        /*
         * Input tax on a purchase DEBIT note, which is a supplier charging us more
         * than the original bill said. It is a purchase, so it recovers tax in the
         * period it is raised - the mirror image of a sales debit note.
         */
        $this->assertSame('20.0000', $totals['input_tax']);
        $this->assertSame('100.0000', $totals['purchase_taxable']);
    }

    #[Test]
    public function a_credit_larger_than_the_period_reports_a_negative_net_rather_than_zero(): void
    {
        $vat = $this->outputTax('20.0000');

        // One credit note that reverses the whole invoice, in a period with no
        // sales of its own.
        $invoice = $this->taxedInvoice($vat);

        $this->publishSalesNote($invoice, NoteType::SalesCreditNote, $vat, '100.00', '2027-03-10');

        $totals = $this->totals('2027-03-01', '2027-03-31');

        /*
         * -20.00, not 0.00.
         *
         * Clamping at zero would report that March involved no output tax at all,
         * which is a different and materially wrong statement: the company owes a
         * refund on sales it already declared in February, and the figure that says
         * so is the negative one. Zero is the answer to "nothing happened"; -20.00
         * is the answer to "this much came back", and only one of them can be filed.
         */
        $this->assertSame('-20.0000', $totals['output_tax']);
        $this->assertSame('-100.0000', $totals['sales_taxable']);
        $this->assertSame('-20.0000', $totals['net_tax']);
    }

    #[Test]
    public function a_draft_note_is_not_reported(): void
    {
        $vat = $this->outputTax('20.0000');
        $invoice = $this->taxedInvoice($vat);

        $this->makePeriodFor($this->company, '2027-03-10', 'P 2027-03'.uniqid());

        $this->createDraftNote($this->accountant, $this->company, $invoice, $this->accounts, [
            'note_date' => '2027-03-10',
            'tax_account_id' => $this->accounts['tax_payable']->getKey(),
            'lines' => [[
                'description' => 'Unposted return',
                'quantity' => '1',
                'unit_price' => '100.00',
                'discount' => '0',
                'tax_rate' => '20',
                'tax_ids' => [$vat->getKey()],
                'account_id' => $this->accounts['revenue']->getKey(),
            ]],
        ]);

        $totals = $this->totals('2027-03-01', '2027-03-31');

        $this->assertSame('0.0000', $totals['output_tax']);
        $this->assertSame('0.0000', $totals['sales_taxable']);
    }

    #[Test]
    public function a_note_is_reported_in_the_month_it_was_raised_not_the_month_it_adjusts(): void
    {
        $vat = $this->outputTax('20.0000');
        $invoice = $this->taxedInvoice($vat);

        /*
         * The invoice is dated 2027-02-10 and the credit note 2027-03-10, and the
         * tax period is decided by the note's own date. A return belongs to the
         * period the return was made in: attributing it to the original sale would
         * let a company reopen a closed period by issuing a credit note against it,
         * and would make two months disagree with the invoices that caused them.
         */
        $this->publishSalesNote($invoice, NoteType::SalesCreditNote, $vat, '100.00', '2027-03-10');

        $february = $this->totals('2027-02-01', '2027-02-28');
        $this->assertSame('20.0000', $february['output_tax']);
        $this->assertSame('100.0000', $february['sales_taxable']);

        $march = $this->totals('2027-03-01', '2027-03-31');
        $this->assertSame('-20.0000', $march['output_tax']);
        $this->assertSame('-100.0000', $march['sales_taxable']);
    }

    #[Test]
    public function by_tax_attributes_the_note_to_the_tax_its_own_line_carried(): void
    {
        $vat = $this->outputTax('20.0000', 'VAT20');

        $invoice = $this->taxedInvoice($vat);

        $this->publishSalesNote($invoice, NoteType::SalesCreditNote, $vat, '100.00', '2027-03-10');

        $rows = collect($this->report('/api/accounting/tax-reports/by-tax?from_date=2027-01-01&to_date=2027-12-31')['rows'])
            ->keyBy('tax_code');

        /*
         * February's 20.00 less March's 20.00 on one row for the one tax, which is
         * what makes the row a usable return: the same VAT code nets to zero rather
         * than two rows that have to be subtracted by hand.
         */
        $this->assertSame($vat->getKey(), $rows['VAT20']['tax_id']);
        $this->assertSame('0.0000', $rows['VAT20']['output_tax']);
        $this->assertSame('0.0000', $rows['VAT20']['sales_taxable']);
        $this->assertSame('0.0000', $rows['VAT20']['net_tax']);
    }

    #[Test]
    public function a_rate_raised_after_the_note_does_not_move_the_period_it_was_reported_in(): void
    {
        $vat = $this->outputTax('20.0000');

        $invoice = $this->taxedInvoice($vat);

        $this->publishSalesNote($invoice, NoteType::SalesCreditNote, $vat, '100.00', '2027-03-10');

        $this->assertSame('-20.0000', $this->totals('2027-03-01', '2027-03-31')['output_tax']);

        /*
         * The historical-rate guarantee, on the note side. The note line snapshotted
         * tax_rate 20 when it was written, and raising the configured rate to 25
         * must not restate what March reported - otherwise a rate change would
         * silently rewrite a filed return.
         */
        TaxRate::query()->where('tax_id', $vat->getKey())->update(['rate' => '25.0000']);

        $this->assertSame('-20.0000', $this->totals('2027-03-01', '2027-03-31')['output_tax']);
    }

    #[Test]
    public function another_tenants_notes_are_not_reported(): void
    {
        $theirs = $this->createUserWithRole('Accountant');
        $theirCompany = $this->createCompanyFor($theirs);
        $theirAccounts = $this->makeTransactionAccounts($theirCompany);
        $theirVat = Tax::factory()->for($theirCompany)->create([
            'code' => 'VAT20',
            'name' => 'VAT20',
            'tax_type' => TaxType::Output,
        ]);
        TaxRate::factory()->for($theirVat)->rate('20.0000')->effectiveFrom('2020-01-01')->create();

        $theirCustomer = $this->createCustomer($theirs, $theirCompany, $theirAccounts);

        $this->makePeriodFor($theirCompany, '2027-02-10', 'P 2027-02'.uniqid());

        $theirInvoice = $this->actingAsJwt($theirs)
            ->withCompanyContext($theirCompany)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $theirCustomer->getKey(),
                'invoice_date' => '2027-02-10',
                'due_date' => '2027-03-10',
                'tax_account_id' => $theirAccounts['tax_payable']->getKey(),
                'lines' => [[
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '20',
                    'tax_ids' => [$theirVat->getKey()],
                    'revenue_account_id' => $theirAccounts['revenue']->getKey(),
                ]],
            ])
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAsJwt($theirs)
            ->withCompanyContext($theirCompany)
            ->postJson("/api/sales-invoices/{$theirInvoice}/post")
            ->assertSuccessful();

        $this->makePeriodFor($theirCompany, '2027-03-10', 'P 2027-03'.uniqid());

        $noteId = $this->actingAsJwt($theirs)
            ->withCompanyContext($theirCompany)
            ->postJson('/api/credit-debit-notes', [
                'note_type' => NoteType::SalesCreditNote->value,
                'sales_invoice_id' => $theirInvoice,
                'note_date' => '2027-03-10',
                'tax_account_id' => $theirAccounts['tax_payable']->getKey(),
                'reason' => 'Another tenant return.',
                'lines' => [[
                    'description' => 'Return',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '20',
                    'tax_ids' => [$theirVat->getKey()],
                    'account_id' => $theirAccounts['revenue']->getKey(),
                ]],
            ])
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAsJwt($theirs)
            ->withCompanyContext($theirCompany)
            ->postJson("/api/credit-debit-notes/{$noteId}/post")
            ->assertSuccessful();

        /*
         * Our ledger is empty, so the whole report is zero. A note belonging to
         * someone else appearing here would be a cross-tenant data leak in a report
         * that companies file with tax authorities.
         */
        $totals = $this->totals('2027-01-01', '2027-12-31');

        $this->assertSame('0.0000', $totals['output_tax']);
        $this->assertSame('0.0000', $totals['sales_taxable']);
        $this->assertSame([], $this->report('/api/accounting/tax-reports/by-tax?from_date=2027-01-01&to_date=2027-12-31')['rows']);
    }

    /**
     * @return array<string, string>
     */
    private function totals(string $from = '2027-01-01', string $to = '2027-12-31'): array
    {
        return $this->report("/api/accounting/tax-reports/summary?from_date={$from}&to_date={$to}")['totals'];
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $url): array
    {
        return $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->getJson($url)
            ->assertSuccessful()
            ->json('data');
    }

    private function taxedInvoice(Tax $vat): SalesInvoice
    {
        $customer = $this->createCustomer($this->accountant, $this->company, $this->accounts);

        $this->makePeriodFor($this->company, '2027-02-10', 'P 2027-02'.uniqid());

        $id = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-02-10',
                'due_date' => '2027-03-10',
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
                'lines' => [[
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '20',
                    'tax_ids' => [$vat->getKey()],
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ]],
            ])
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/sales-invoices/{$id}/post")
            ->assertSuccessful();

        return SalesInvoice::findOrFail($id);
    }

    private function taxedBill(Tax $vat): int
    {
        $supplier = $this->createSupplier($this->accountant, $this->company, $this->accounts);

        $this->makePeriodFor($this->company, '2027-02-10', 'P 2027-02'.uniqid());

        $id = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $supplier->getKey(),
                'bill_date' => '2027-02-10',
                'due_date' => '2027-03-10',
                'tax_account_id' => $this->accounts['input_tax']->getKey(),
                'lines' => [[
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_cost' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '20',
                    'tax_ids' => [$vat->getKey()],
                    'expense_account_id' => $this->accounts['expense']->getKey(),
                ]],
            ])
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/purchase-bills/{$id}/post")
            ->assertSuccessful();

        return (int) $id;
    }

    private function publishSalesNote(SalesInvoice $invoice, NoteType $type, Tax $vat, string $unitPrice, string $date): void
    {
        $this->publishNote($type, $vat, $unitPrice, $date, [
            'sales_invoice_id' => $invoice->getKey(),
            'tax_account_id' => $this->accounts['tax_payable']->getKey(),
            'account_id' => $this->accounts['revenue']->getKey(),
        ]);
    }

    private function publishPurchaseNote(int $billId, NoteType $type, Tax $vat, string $unitPrice, string $date): void
    {
        $this->publishNote($type, $vat, $unitPrice, $date, [
            'purchase_bill_id' => $billId,
            'tax_account_id' => $this->accounts['input_tax']->getKey(),
            'account_id' => $this->accounts['expense']->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function publishNote(NoteType $type, Tax $vat, string $unitPrice, string $date, array $document): void
    {
        $this->makePeriodFor($this->company, $date, 'P '.substr($date, 0, 7).uniqid());

        $accountId = $document['account_id'];

        $id = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/credit-debit-notes', array_merge($document, [
                'note_type' => $type->value,
                'note_date' => $date,
                'reason' => 'Tax report note.',
                'lines' => [[
                    'description' => 'Return',
                    'quantity' => '1',
                    'unit_price' => $unitPrice,
                    'discount' => '0',
                    'tax_rate' => '20',
                    'tax_ids' => [$vat->getKey()],
                    'account_id' => $accountId,
                ]],
            ]))
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/credit-debit-notes/{$id}/post")
            ->assertSuccessful();
    }

    private function outputTax(string $rate, string $code = 'VAT20'): Tax
    {
        return $this->tax($rate, $code, TaxType::Output);
    }

    private function inputTax(string $rate, string $code = 'VATIN20'): Tax
    {
        return $this->tax($rate, $code, TaxType::Input);
    }

    private function tax(string $rate, string $code, TaxType $type): Tax
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => $code,
            'name' => $code,
            'tax_type' => $type,
        ]);

        TaxRate::factory()->for($tax)->rate($rate)->effectiveFrom('2020-01-01')->create();

        return $tax;
    }
}
