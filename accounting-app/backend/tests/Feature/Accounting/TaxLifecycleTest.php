<?php

namespace Tests\Feature\Accounting;

use App\Enums\TaxType;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Switching taxes and rates off, and the one thing they must never switch off:
 * what was already charged.
 *
 * Deactivation is the brief's answer to "how do we stop charging a tax we cannot
 * delete", so the interesting question is not whether it flips `is_active` - it
 * does - but what a deactivated tax still owes the record. Everything here is
 * arranged so that a naive implementation, one that deleted the row or rewrote
 * history, would fail loudly.
 *
 * Resolution is asserted through `/api/accounting/tax/calculate`, which is the one
 * place the resolver's answer is visible without building a whole document.
 */
class TaxLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->createUserWithRole('Accountant');
        $this->company = $this->createCompanyFor($this->accountant);
    }

    #[Test]
    public function a_tax_can_be_deactivated(): void
    {
        $tax = $this->createTax();

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/deactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Tax deactivated successfully.')
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($tax->fresh()->is_active);
    }

    /**
     * A deactivated tax cannot be charged by accident even if a stale client still
     * sends its id.
     *
     * Worth stating plainly, because the alternative was live: silently treating an
     * inactive tax as "no tax" would issue an untaxed document that looked
     * deliberate and was under-charged. A 422 names the problem, and the caller
     * fixes the document rather than filing it.
     */
    #[Test]
    public function naming_a_deactivated_tax_is_refused_rather_than_charged_at_zero(): void
    {
        $tax = $this->createTax();

        $this->assertSame('10.0000', $this->calculateTax($tax));

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/deactivate")->assertOk();

        $this->assertRefusedFor($tax, 'is inactive');
    }

    /**
     * Deactivation is a stop, not a deletion. A tax that a return, an audit or an
     * old document refers to must remain readable afterwards.
     */
    #[Test]
    public function a_deactivated_tax_is_still_readable(): void
    {
        $tax = $this->createTax();

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/deactivate")->assertOk();

        $this->callGet("/api/accounting/taxes/{$tax->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.code', 'VAT')
            ->assertJsonPath('data.is_active', false);
    }

    #[Test]
    public function a_tax_can_be_reactivated(): void
    {
        $tax = $this->createTax();

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/deactivate")->assertOk();
        $this->assertRefusedFor($tax, 'is inactive');

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertSame('10.0000', $this->calculateTax($tax), 'Reactivating resumes charging the rate already in force.');
    }

    /**
     * The point of deactivation. A document already charged at 10% must keep its
     * 10% snapshot after the tax is switched off, because the tax was genuinely
     * owed on the day it was raised.
     */
    #[Test]
    public function deactivating_a_tax_does_not_alter_a_document_already_charged_with_it(): void
    {
        $tax = $this->createTax();

        $accounts = $this->makeTransactionAccounts($this->company);
        $customer = $this->createCustomer($this->accountant, $this->company, $accounts);

        $this->makePeriodFor($this->company, '2027-01-10', 'P 2027-01');

        $created = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/sales-invoices', [
                'customer_id' => $customer->getKey(),
                'invoice_date' => '2027-01-10',
                'due_date' => '2027-02-10',
                'tax_account_id' => $accounts['tax_payable']->getKey(),
                'lines' => [[
                    'description' => 'Widget',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                    'tax_ids' => [$tax->getKey()],
                    'revenue_account_id' => $accounts['revenue']->getKey(),
                ]],
            ]);

        $created->assertCreated();

        $invoice = SalesInvoice::findOrFail($created->json('data.id'));

        $this->assertSame('10.0000', $invoice->tax_total);

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/deactivate")->assertOk();

        $invoice->refresh();

        $this->assertSame('10.0000', $invoice->tax_total, 'The tax was owed when it was charged.');
        $this->assertSame('10.0000', $invoice->lines()->sole()->tax_rate);
        $this->assertSame($tax->getKey(), $invoice->lines()->sole()->tax_id);
    }

    #[Test]
    public function a_rate_can_be_deactivated_and_the_tax_stays_usable(): void
    {
        [$tax, $rate] = $this->createTaxAndRate();

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/rates/{$rate->getKey()}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertTrue($tax->fresh()->is_active, 'Only the rate was retired.');

        // The tax is active but has nothing to charge, and says so instead of
        // quietly quoting the untaxed amount.
        $this->assertRefusedFor($tax, 'no rate in force');
    }

    #[Test]
    public function a_rate_can_be_reactivated(): void
    {
        [$tax, $rate] = $this->createTaxAndRate();

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/rates/{$rate->getKey()}/deactivate")->assertOk();
        $this->assertRefusedFor($tax, 'no rate in force');

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/rates/{$rate->getKey()}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertSame('10.0000', $this->calculateTax($tax));
    }

    /**
     * The rate history still explains what a document was charged, which is why a
     * rate is retired rather than deleted.
     */
    #[Test]
    public function a_deactivated_rate_is_still_readable(): void
    {
        [$tax, $rate] = $this->createTaxAndRate();

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/rates/{$rate->getKey()}/deactivate")->assertOk();

        $this->callGet("/api/accounting/taxes/{$tax->getKey()}/rates")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    }

    /**
     * A rate id reached through the wrong tax cannot be edited, whichever parent
     * tax the caller is able to see.
     *
     * Rejected as a 422 by the request's rule before any method runs, rather than by
     * the controller's own lookup - the rule is scoped to the parent tax, so it
     * fails the same way whether or not the id exists at all. What matters for
     * tenancy is the last assertion: the other tax's rate is untouched.
     */
    #[Test]
    public function a_rate_cannot_be_reached_through_the_wrong_tax(): void
    {
        $tax = $this->createTax(['code' => 'VAT']);

        [, $otherRate] = $this->createTaxAndRate('OTHER');

        $this->callPost("/api/accounting/taxes/{$tax->getKey()}/rates/{$otherRate->getKey()}/deactivate")
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');

        $this->assertTrue($otherRate->fresh()->is_active, "Another tax's rate must be untouched.");
    }

    /**
     * Deactivation is gated as an update, so Manager - read-only over tax by the
     * phase's own decision - cannot switch a tax off.
     */
    #[Test]
    public function a_manager_may_not_deactivate_a_tax(): void
    {
        $manager = $this->createUserWithRole('Manager');
        $managerCompany = $this->createCompanyFor($manager);

        $tax = Tax::factory()->for($managerCompany)->create(['code' => 'VAT']);

        $this->actingAsJwt($manager)
            ->withCompanyContext($managerCompany)
            ->postJson("/api/accounting/taxes/{$tax->getKey()}/deactivate")
            ->assertForbidden();

        $this->assertTrue($tax->fresh()->is_active, 'A refused deactivation must leave the tax active.');
    }

    #[Test]
    public function a_user_without_tax_access_may_not_deactivate_a_rate(): void
    {
        $staff = $this->createUserWithRole('Staff');
        $staffCompany = $this->createCompanyFor($staff);

        [$tax, $rate] = $this->createTaxAndRate('STAFF', $staffCompany);

        $this->actingAsJwt($staff)
            ->withCompanyContext($staffCompany)
            ->postJson("/api/accounting/taxes/{$tax->getKey()}/rates/{$rate->getKey()}/deactivate")
            ->assertForbidden();

        $this->assertTrue($rate->fresh()->is_active);
    }

    /**
     * A description has to be clearable. Treating an explicit null as "field
     * absent" would leave a description that could be set once and never removed.
     */
    #[Test]
    public function a_description_can_be_cleared(): void
    {
        $tax = $this->createTax(['description' => 'Standard rate']);

        $this->callPut("/api/accounting/taxes/{$tax->getKey()}", [
            'code' => 'VAT',
            'name' => 'VAT',
            'description' => null,
        ])->assertOk();

        $this->assertNull($tax->fresh()->description);
    }

    #[Test]
    public function omitting_a_description_leaves_the_existing_one_alone(): void
    {
        $tax = $this->createTax(['description' => 'Standard rate']);

        $this->callPut("/api/accounting/taxes/{$tax->getKey()}", [
            'code' => 'VAT',
            'name' => 'VAT renamed',
        ])->assertOk();

        $this->assertSame('Standard rate', $tax->fresh()->description, 'An absent field is not an instruction to clear it.');
    }

    private function createTax(array $overrides = []): Tax
    {
        $tax = Tax::factory()->for($this->company)->create(array_merge([
            'code' => 'VAT',
            'name' => 'VAT',
            'tax_type' => TaxType::Output,
        ], $overrides));

        TaxRate::factory()->for($tax)->rate('10.0000')->effectiveFrom('2020-01-01')->create();

        return $tax;
    }

    /**
     * @return array{0: Tax, 1: TaxRate}
     */
    private function createTaxAndRate(string $code = 'RATED', ?Company $company = null): array
    {
        $tax = Tax::factory()->for($company ?? $this->company)->create([
            'code' => $code,
            'name' => $code,
            'tax_type' => TaxType::Output,
        ]);

        $rate = TaxRate::factory()->for($tax)->rate('10.0000')->effectiveFrom('2020-01-01')->create();

        return [$tax, $rate];
    }

    /**
     * The tax total the calculate endpoint returns for 100.00 naming this tax.
     */
    private function calculateTax(Tax $tax): string
    {
        $response = $this->calculate($tax);

        $response->assertOk();

        return (string) $response->json('data.total_tax');
    }

    /**
     * Assert that naming this tax is refused, and for the stated reason.
     *
     * The reason is matched as a substring because the messages are written for
     * humans and interpolate the tax's code and the date; pinning the whole
     * sentence would make this test fail on a wording improvement.
     */
    private function assertRefusedFor(Tax $tax, string $because): void
    {
        $response = $this->calculate($tax);

        $response->assertStatus(422);

        $this->assertStringContainsString($because, (string) $response->json('message'));
    }

    private function calculate(Tax $tax)
    {
        return $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/tax/calculate', [
                'amount' => '100.0000',
                'date' => '2026-06-15',
                'tax_ids' => [$tax->getKey()],
            ]);
    }

    private function callGet(string $url)
    {
        return $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->getJson($url);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callPost(string $url, array $payload = [])
    {
        return $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson($url, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callPut(string $url, array $payload = [])
    {
        return $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson($url, $payload);
    }
}
