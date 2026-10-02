<?php

namespace Tests\Feature\Accounting;

use App\Enums\TaxCalculationBasis;
use App\Enums\TaxType;
use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A configured tax, applied to a real invoice and a real bill.
 *
 * Everything else in the tax suite tests the engine in isolation. This file is
 * about the seam: that naming a tax on a document line changes the document, that
 * the change is persisted as a snapshot, that the document's own totals still hold,
 * and - the part that matters most - that the ledger the existing posting services
 * build out of those lines is still balanced and still puts the tax in the tax
 * account rather than in revenue.
 *
 * The Phase 5 flows are not replaced. A line that names no tax keeps its
 * hand-entered rate, and that is asserted here too, because a change to the
 * document path that quietly altered untaxed documents would be worse than no
 * change at all.
 */
class TaxDocumentIntegrationTest extends TestCase
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
    public function an_invoice_line_naming_a_tax_is_charged_that_tax_rate(): void
    {
        $tax = $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $invoice = $this->invoice(['tax_ids' => [$tax->getKey()]]);

        /*
         * 2 x 100.00 = 200.00 net, plus 10% = 20.00, giving 220.00 due. The rate
         * is asserted as stored, because a line charged 10% that recorded some
         * other percentage would explain nothing to a reader of the document.
         */
        $this->assertSame('200.0000', $invoice->subtotal);
        $this->assertSame('20.0000', $invoice->tax_total);
        $this->assertSame('220.0000', $invoice->grand_total);

        $line = $invoice->lines()->sole();

        $this->assertSame('10.0000', $line->tax_rate);
        $this->assertSame('20.0000', $line->tax_amount);
        $this->assertSame('220.0000', $line->line_total);
    }

    /**
     * The snapshot is the point of writing tax_id at all: a report six months later
     * can say which tax produced this figure without re-reading configuration that
     * may since have changed.
     */
    #[Test]
    public function the_configured_tax_is_snapshotted_onto_the_line(): void
    {
        $tax = $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $line = $this->invoice(['tax_ids' => [$tax->getKey()]])->lines()->sole();

        $this->assertSame($tax->getKey(), $line->tax_id);
    }

    /**
     * The snapshot does not follow the configuration. Changing a tax's rate
     * afterwards changes what future documents are charged, never what this one was.
     */
    #[Test]
    public function a_rate_change_does_not_alter_an_existing_document(): void
    {
        $tax = $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $invoice = $this->invoice(['tax_ids' => [$tax->getKey()]]);

        // A new rate from April, closing the open-ended 10% the day before.
        TaxRate::query()->where('tax_id', $tax->getKey())->update([
            'effective_to' => Carbon::parse('2027-03-31')->toDateString(),
        ]);

        TaxRate::factory()->for($tax)->rate('20.0000')
            ->effectiveFrom('2027-04-01')->create();

        $invoice->refresh()->load('lines');
        $line = $invoice->lines()->sole();

        $this->assertSame('10.0000', $line->tax_rate, 'A posted document keeps the rate it was calculated with.');
        $this->assertSame('20.0000', $line->tax_amount);
        $this->assertSame('220.0000', $invoice->grand_total);
    }

    /**
     * Two taxes are charged on the same base, not on each other: 10% and 5% of 200
     * is 20 and 10, not 20 and 11.
     */
    #[Test]
    public function two_taxes_on_one_line_are_charged_in_parallel(): void
    {
        $state = $this->outputTax('10.0000', 'STATE');
        $county = $this->outputTax('5.0000', 'COUNTY');

        $this->makePeriod('2027-01-10');

        $invoice = $this->invoice(['tax_ids' => [$state->getKey(), $county->getKey()]]);
        $line = $invoice->lines()->sole();

        $this->assertSame('30.0000', $line->tax_amount);
        $this->assertSame('230.0000', $invoice->grand_total);
        $this->assertSame('15.0000', $line->tax_rate, 'The stored rate is the sum of the components.');
    }

    /**
     * The snapshot column holds one id and two taxes do not fit in it. Storing null
     * is honest; storing either tax would attribute all of the amount to a tax that
     * only charged part of it, and a report would then be wrong rather than
     * incomplete.
     */
    #[Test]
    public function a_multi_tax_line_snapshots_no_single_tax(): void
    {
        $state = $this->outputTax('10.0000', 'STATE');
        $county = $this->outputTax('5.0000', 'COUNTY');

        $this->makePeriod('2027-01-10');

        $line = $this->invoice(['tax_ids' => [$state->getKey(), $county->getKey()]])->lines()->sole();

        $this->assertNull($line->tax_id);
    }

    /**
     * The document's own date decides the rate, not today.
     */
    #[Test]
    public function the_rate_comes_from_the_documents_date(): void
    {
        $tax = $this->outputTax('10.0000');

        TaxRate::query()->where('tax_id', $tax->getKey())->update([
            'effective_to' => Carbon::parse('2027-03-31')->toDateString(),
        ]);

        TaxRate::factory()->for($tax)->rate('20.0000')->effectiveFrom('2027-04-01')->create();

        $this->makePeriod('2027-02-10');

        $february = $this->invoice(['tax_ids' => [$tax->getKey()]]);
        $this->assertSame('20.0000', $february->tax_total);

        $this->makePeriod('2027-05-10');

        $may = $this->invoice(
            ['tax_ids' => [$tax->getKey()]],
            ['invoice_date' => '2027-05-10', 'due_date' => '2027-06-10'],
        );
        $this->assertSame('40.0000', $may->tax_total);
    }

    /**
     * A line may carry both a percentage and tax ids. The ids win, because a line
     * naming a tax must be charged that tax's rate on its date - otherwise the two
     * inputs would disagree and the document would silently follow the one that is
     * easier to type.
     */
    #[Test]
    public function a_named_tax_takes_precedence_over_a_typed_rate(): void
    {
        $tax = $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $invoice = $this->invoice(['tax_ids' => [$tax->getKey()], 'tax_rate' => '99.0000']);

        $this->assertSame('20.0000', $invoice->tax_total);
    }

    /**
     * Phase 5 behaviour, unchanged: a line with no configured tax is charged the
     * percentage on it and snapshots nothing.
     */
    #[Test]
    public function a_line_with_only_a_typed_rate_is_unchanged(): void
    {
        $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $invoice = $this->invoice(['tax_rate' => '20.0000']);
        $line = $invoice->lines()->sole();

        $this->assertSame('20.0000', $line->tax_rate);
        $this->assertSame('40.0000', $invoice->tax_total);
        $this->assertNull($line->tax_id);
    }

    /**
     * An empty list means "no taxes on this line", not "every tax in the company".
     */
    #[Test]
    public function a_line_naming_no_taxes_is_untaxed(): void
    {
        $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $invoice = $this->invoice(['tax_ids' => [], 'tax_rate' => '0']);

        $this->assertSame('0.0000', $invoice->tax_total);
        $this->assertSame('200.0000', $invoice->grand_total);
    }

    /**
     * The ledger. Revenue is credited net, the tax liability is credited the tax,
     * and the entry balances - which is the existing posting service doing its job
     * over the figures the engine produced.
     */
    #[Test]
    public function a_posted_invoice_puts_the_tax_in_the_tax_account(): void
    {
        $tax = $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $invoice = $this->postWithTax(['tax_ids' => [$tax->getKey()]]);

        $journal = $invoice->journal;

        $this->assertSame(TransactionStatus::Posted, $invoice->status);
        $this->assertTrue($journal->isBalanced(), 'The entry must balance after the engine produced the figures.');

        $revenue = $journal->lines()->where('account_id', $this->accounts['revenue']->getKey())->sole();
        $taxLine = $journal->lines()->where('account_id', $this->accounts['tax_payable']->getKey())->sole();
        $receivable = $journal->lines()->where('account_id', $this->accounts['receivable']->getKey())->sole();

        $this->assertSame('200.0000', $revenue->creditAmount()->toDatabase(), 'Revenue must be net, not gross with the tax inside it.');
        $this->assertSame('0.0000', $revenue->debitAmount()->toDatabase());
        $this->assertSame('20.0000', $taxLine->creditAmount()->toDatabase());
        $this->assertSame('0.0000', $taxLine->debitAmount()->toDatabase());
        $this->assertSame('220.0000', $receivable->debitAmount()->toDatabase());
        $this->assertSame('0.0000', $receivable->creditAmount()->toDatabase());
    }

    /**
     * The same seam on the purchase side, with the sides swapped: an INPUT tax
     * recovers on a bill, and an OUTPUT tax has no business there.
     */
    #[Test]
    public function a_bill_line_naming_an_input_tax_recovers_it(): void
    {
        $tax = $this->inputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $bill = $this->bill(['tax_ids' => [$tax->getKey()]]);

        $this->assertSame('20.0000', $bill->tax_total);
        $this->assertSame('220.0000', $bill->grand_total);
        $this->assertSame($tax->getKey(), $bill->lines()->sole()->tax_id);
    }

    #[Test]
    public function an_output_tax_cannot_be_named_on_a_bill(): void
    {
        $tax = $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/purchase-bills', [
                'supplier_id' => $this->supplier->getKey(),
                'bill_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $this->accounts['input_tax']->getKey(),
                'lines' => [[
                    'description' => 'Widget',
                    'quantity' => '2',
                    'unit_cost' => '100.00',
                    'discount' => '0',
                    'tax_ids' => [$tax->getKey()],
                    'expense_account_id' => $this->accounts['expense']->getKey(),
                ]],
            ])
            ->assertStatus(422);
    }

    /**
     * A tax from another company cannot be reached through a document line, which
     * goes through the resolver rather than through the tax endpoint's own scoping.
     */
    #[Test]
    public function another_companys_tax_cannot_be_named_on_a_line(): void
    {
        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);
        $foreign = Tax::factory()->for($other)->create(['code' => 'VAT', 'tax_type' => TaxType::Output]);
        TaxRate::factory()->for($foreign)->rate('10.0000')->create();

        $this->makePeriod('2027-01-10');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $this->customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
                'lines' => [[
                    'description' => 'Widget',
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'tax_ids' => [$foreign->getKey()],
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ]],
            ])
            ->assertStatus(422);
    }

    /**
     * A tax with no rate on the document's date is refused rather than charged as
     * zero: the user asked for a tax, and silently applying none of it would
     * understate what they owe.
     */
    #[Test]
    public function a_tax_with_no_rate_on_the_documents_date_is_refused(): void
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => 'LATER',
            'name' => 'Later Tax',
            'tax_type' => TaxType::Output,
            'calculation_basis' => TaxCalculationBasis::Exclusive,
        ]);

        TaxRate::factory()->for($tax)->rate('10.0000')->effectiveFrom('2027-06-01')->create();

        $this->makePeriod('2027-01-10');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $this->customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
                'lines' => [[
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'tax_ids' => [$tax->getKey()],
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ]],
            ])
            ->assertStatus(422);
    }

    /**
     * INCLUSIVE is refused on a document, with a message that says why rather than
     * a bare rejection. The engine supports it; the document's totals cannot, and a
     * user who has just been told that can act on it.
     */
    #[Test]
    public function an_inclusive_tax_is_refused_on_a_document(): void
    {
        $tax = $this->outputTax('10.0000', 'INCVAT', TaxCalculationBasis::Inclusive);

        $this->makePeriod('2027-01-10');

        $response = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $this->customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
                'lines' => [[
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'tax_ids' => [$tax->getKey()],
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ]],
            ]);

        $response->assertStatus(422);

        $this->assertStringContainsString(
            'INCLUSIVE',
            json_encode($response->json('errors'))
        );
    }

    /**
     * A tax that produces money needs somewhere to put it, exactly as a hand-entered
     * rate always has.
     */
    #[Test]
    public function a_taxed_document_with_no_tax_account_is_refused(): void
    {
        $tax = $this->outputTax('10.0000');

        $this->makePeriod('2027-01-10');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $this->customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'lines' => [[
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'tax_ids' => [$tax->getKey()],
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_account_id');
    }

    /**
     * An open accounting period covering a date, so a document on it can post.
     *
     * Named for the date rather than taking a range because every document in this
     * file is dated in one month, and a period that had to be opened twice - once
     * for February and once for May - would obscure which date the assertion about
     * rate resolution was actually about.
     */
    private function makePeriod(string $date): void
    {
        $this->makePeriodFor($this->company, $date, 'P '.substr($date, 0, 7).uniqid());
    }

    /**
     * @param  array<string, mixed>  $lineOverrides
     */
    private function invoice(array $lineOverrides = [], array $documentOverrides = []): SalesInvoice
    {
        $response = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/sales-invoices', array_merge([
                'customer_id' => $this->customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $this->accounts['tax_payable']->getKey(),
                'lines' => [array_merge([
                    'description' => 'Widget',
                    'quantity' => '2',
                    'unit_price' => '100.00',
                    'discount' => '0',
                    'tax_rate' => '0',
                    'revenue_account_id' => $this->accounts['revenue']->getKey(),
                ], $lineOverrides)],
            ], $documentOverrides));

        $response->assertSuccessful();

        return SalesInvoice::findOrFail($response->json('data.id'));
    }

    /**
     * @param  array<string, mixed>  $lineOverrides
     */
    private function postWithTax(array $lineOverrides = []): SalesInvoice
    {
        $invoice = $this->invoice($lineOverrides);

        /*
         * The body is dumped on failure because a 422 from posting names the
         * account or the period that was wrong, and a bare status assertion would
         * leave which one to a bisect.
         */
        $response = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/sales-invoices/{$invoice->getKey()}/post");

        if (! $response->isSuccessful()) {
            $this->fail('Posting failed: '.$response->getContent());
        }

        return $invoice->fresh(['journal.lines']);
    }

    /**
     * @param  array<string, mixed>  $lineOverrides
     */
    private function bill(array $lineOverrides = []): PurchaseBill
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

        return PurchaseBill::findOrFail($response->json('data.id'));
    }

    private function outputTax(
        string $rate,
        string $code = 'VAT',
        TaxCalculationBasis $basis = TaxCalculationBasis::Exclusive,
    ): Tax {
        return $this->tax($rate, $code, TaxType::Output, $basis);
    }

    private function inputTax(string $rate, string $code = 'VATIN'): Tax
    {
        return $this->tax($rate, $code, TaxType::Input);
    }

    private function tax(string $rate, string $code, TaxType $type, TaxCalculationBasis $basis = TaxCalculationBasis::Exclusive): Tax
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => $code,
            'name' => $code,
            'tax_type' => $type,
            'calculation_basis' => $basis,
        ]);

        TaxRate::factory()->for($tax)->rate($rate)->effectiveFrom('2020-01-01')->create();

        return $tax;
    }
}
