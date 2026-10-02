<?php

namespace Tests\Feature\Accounting;

use App\Enums\TaxCalculationBasis;
use App\Enums\TaxType;
use App\Models\Account;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Creating, changing and retiring tax configuration over HTTP.
 *
 * The lifecycle rules being tested here are the ones that keep history readable:
 * a tax that documents depend on cannot be deleted, and one that is merely unused
 * can.
 */
class TaxConfigurationTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'VAT',
            'name' => 'Value Added Tax',
            'description' => 'Standard rate on sales',
            'tax_type' => TaxType::Output->value,
            'calculation_basis' => TaxCalculationBasis::Exclusive->value,
            ...$overrides,
        ];
    }

    #[Test]
    public function a_tax_can_be_created(): void
    {
        $response = $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/taxes', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.code', 'VAT')
            ->assertJsonPath('data.tax_type', 'OUTPUT')
            ->assertJsonPath('data.calculation_basis', 'EXCLUSIVE')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.applies_to_sales', true)
            ->assertJsonPath('data.applies_to_purchase', false);

        $this->assertDatabaseHas('taxes', [
            'company_id' => $this->company->getKey(),
            'code' => 'VAT',
        ]);
    }

    /**
     * A tax is created active and attributed to the authenticated user, never to
     * whatever the client said.
     */
    #[Test]
    public function a_created_tax_records_who_configured_it(): void
    {
        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/taxes', $this->payload(['created_by' => 999999]))
            ->assertCreated()
            ->assertJsonPath('data.created_by', $this->accountant->getKey());
    }

    /**
     * company_id is never taken from the client, in any of its spellings.
     */
    #[Test]
    public function a_client_cannot_choose_the_company(): void
    {
        $other = Company::factory()->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/taxes', $this->payload([
                'company_id' => $other->getKey(),
            ]))
            ->assertCreated();

        $this->assertDatabaseHas('taxes', [
            'company_id' => $this->company->getKey(),
            'code' => 'VAT',
        ]);

        $this->assertDatabaseMissing('taxes', [
            'company_id' => $other->getKey(),
        ]);
    }

    #[Test]
    public function a_tax_code_must_be_unique_within_a_company(): void
    {
        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/taxes', $this->payload())
            ->assertCreated();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/taxes', $this->payload(['name' => 'Duplicate']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    /**
     * Uniqueness is per company: two companies may both have a VAT.
     */
    #[Test]
    public function the_same_code_may_exist_in_two_companies(): void
    {
        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/taxes', $this->payload())
            ->assertCreated();

        $this->actingAsJwt($otherOwner)
            ->withCompanyContext($other)
            ->postJson('/api/accounting/taxes', $this->payload())
            ->assertCreated();
    }

    #[Test]
    public function an_unknown_tax_type_is_rejected(): void
    {
        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/taxes', $this->payload(['tax_type' => 'CGST']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_type');
    }

    #[Test]
    public function a_tax_can_be_updated(): void
    {
        $tax = $this->createTax();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}", [
                'name' => 'Renamed Tax',
                'tax_type' => TaxType::Both->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Tax')
            ->assertJsonPath('data.tax_type', 'BOTH')
            ->assertJsonPath('data.applies_to_sales', true)
            ->assertJsonPath('data.applies_to_purchase', true)
            ->assertJsonPath('data.updated_by', $this->accountant->getKey());
    }

    /**
     * An omitted field is left alone, not cleared: PUT here means "change what I
     * sent", and a client that sends only a new name must not lose the tax type.
     */
    #[Test]
    public function an_omitted_field_is_unchanged(): void
    {
        $tax = $this->createTax();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}", ['name' => 'Renamed Only'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Only')
            ->assertJsonPath('data.code', 'VAT')
            ->assertJsonPath('data.tax_type', 'OUTPUT');
    }

    /**
     * Re-submitting the tax's own code is not a duplicate.
     */
    #[Test]
    public function a_tax_may_keep_its_own_code(): void
    {
        $tax = $this->createTax();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}", ['code' => 'VAT', 'name' => 'Same Code'])
            ->assertOk();
    }

    /**
     * `is_active` is not an updatable field.
     *
     * A PUT is "change these columns"; retirement is a lifecycle transition with its
     * own service method, so a client cannot flip a tax off by including is_active in
     * an update, and cannot flip one on either. Deleting an unused tax is the other
     * way out of the list.
     */
    #[Test]
    public function is_active_cannot_be_changed_by_an_update(): void
    {
        $tax = $this->createTax();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertTrue($tax->fresh()->is_active);
    }

    #[Test]
    public function a_tax_cannot_be_deleted_once_a_document_uses_it(): void
    {
        $tax = $this->createTax();

        $this->attachDocumentLine($tax);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->deleteJson("/api/accounting/taxes/{$tax->getKey()}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax');

        $this->assertDatabaseHas('taxes', ['id' => $tax->getKey()]);
    }

    #[Test]
    public function a_tax_with_rates_cannot_be_deleted(): void
    {
        $tax = $this->createTax();

        TaxRate::factory()->for($tax)->rate('10.0000')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->deleteJson("/api/accounting/taxes/{$tax->getKey()}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax');
    }

    #[Test]
    public function an_unused_tax_can_be_deleted(): void
    {
        $tax = $this->createTax();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->deleteJson("/api/accounting/taxes/{$tax->getKey()}")
            ->assertOk()
            ->assertJsonPath('message', 'Tax deleted.');

        $this->assertDatabaseMissing('taxes', ['id' => $tax->getKey()]);
    }

    #[Test]
    public function taxes_can_be_listed_and_filtered_to_active_only(): void
    {
        $active = $this->createTax();
        $inactive = $this->createTax(['code' => 'OLD', 'name' => 'Old Tax']);

        $inactive->forceFill(['is_active' => false])->save();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->getJson('/api/accounting/taxes')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->getJson('/api/accounting/taxes?active_only=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->getKey());
    }

    #[Test]
    public function a_tax_can_be_fetched(): void
    {
        $tax = $this->createTax();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->getJson("/api/accounting/taxes/{$tax->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.code', 'VAT');
    }

    private function createTax(array $attributes = []): Tax
    {
        return Tax::factory()->for($this->company)->create([
            'code' => 'VAT',
            'name' => 'Value Added Tax',
            'tax_type' => TaxType::Output,
            ...$attributes,
        ]);
    }

    /**
     * A sales line naming the tax, which is what makes deletion unsafe.
     *
     * Created through the invoice's own `lines()` relation rather than the line
     * factory: the line's relation is named `invoice`, so `->for($invoice)` on the
     * factory would look for a `salesInvoice` method that does not exist. Going
     * through the parent sets the foreign key without depending on the guess.
     */
    private function attachDocumentLine(Tax $tax): SalesInvoiceLine
    {
        $invoice = SalesInvoice::factory()->for($this->company)->create();

        return $invoice->lines()->create([
            'line_number' => 1,
            'description' => 'Taxed line',
            'quantity' => '100.0000',
            'unit_price' => '100.0000',
            'discount' => '0.0000',
            'tax_rate' => '10.0000',
            'tax_amount' => '10.0000',
            'line_total' => '110.0000',
            'tax_id' => $tax->getKey(),
            'revenue_account_id' => Account::factory()->for($this->company)->revenue()->create([
                'code' => '4000',
                'name' => 'Sales Revenue',
            ])->getKey(),
        ]);
    }
}
