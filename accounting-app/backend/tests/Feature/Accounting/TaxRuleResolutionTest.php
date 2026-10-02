<?php

namespace Tests\Feature\Accounting;

use App\Enums\TaxType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Tax;
use App\Models\TaxAccountMapping;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Accounting\Tax\TaxRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which taxes apply to a document, and where their amounts land.
 *
 * Two separate questions are covered here because they fail in different ways:
 * resolution decides *which* taxes an untouched document picks up on its own, and
 * the mapping decides *which account* the amount is posted to. The mapping half is
 * where a silent misconfiguration would be most expensive - a tax on a sale
 * posted to an expense account produces a trial balance that still balances and
 * is still wrong.
 */
class TaxRuleResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Company $company;

    private TaxRuleResolver $resolver;

    private Account $salesLiability;

    private Account $purchaseAsset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->createUserWithRole('Accountant');
        $this->company = $this->createCompanyFor($this->accountant);
        $this->resolver = app(TaxRuleResolver::class);

        $this->salesLiability = Account::factory()->for($this->company)->liability()->create([
            'code' => '2100',
            'name' => 'Sales Tax Payable',
        ]);

        $this->purchaseAsset = Account::factory()->for($this->company)->asset()->create([
            'code' => '1500',
            'name' => 'Recoverable Tax',
        ]);
    }

    #[Test]
    public function an_output_tax_applies_to_sales_and_an_input_tax_to_purchases(): void
    {
        $output = $this->tax('VAT', TaxType::Output);
        $input = $this->tax('VATIN', TaxType::Input);

        $onSales = $this->resolver->resolve($this->company, true, '2026-06-15');
        $onPurchase = $this->resolver->resolve($this->company, false, '2026-06-15');

        $this->assertSame([$output->id], $onSales->pluck('id')->all());
        $this->assertSame([$input->id], $onPurchase->pluck('id')->all());
    }

    #[Test]
    public function a_tax_of_both_types_applies_to_each_side(): void
    {
        $both = $this->tax('GST', TaxType::Both);

        $this->assertSame([$both->id], $this->resolver->resolve($this->company, true, '2026-06-15')->pluck('id')->all());
        $this->assertSame([$both->id], $this->resolver->resolve($this->company, false, '2026-06-15')->pluck('id')->all());
    }

    #[Test]
    public function an_inactive_tax_is_not_resolved(): void
    {
        $tax = $this->tax('VAT', TaxType::Output);
        $tax->forceFill(['is_active' => false])->save();

        $this->assertTrue($this->resolver->resolve($this->company, true, '2026-06-15')->isEmpty());
    }

    #[Test]
    public function another_companys_tax_is_never_resolved(): void
    {
        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);

        Tax::factory()->for($other)->create(['code' => 'VAT', 'tax_type' => TaxType::Output]);

        $this->assertTrue($this->resolver->resolve($this->company, true, '2026-06-15')->isEmpty());
    }

    /**
     * An explicitly empty list means "no taxes".
     *
     * The regression this guards is a falsey-collection bug that reads as harmless:
     * `tax_ids: []` silently became *every* applicable tax, so a document the user
     * had explicitly marked untaxed was charged tax on all of them. Asserted
     * against a tax that would otherwise certainly be returned.
     */
    #[Test]
    public function an_explicitly_empty_list_of_taxes_resolves_to_no_taxes(): void
    {
        $this->tax('VAT', TaxType::Output);
        $this->tax('CGST', TaxType::Output);

        $resolved = $this->resolver->resolve($this->company, true, '2026-06-15', new Collection);

        $this->assertTrue($resolved->isEmpty(), 'An empty tax_ids list must not fall back to every applicable tax.');
    }

    #[Test]
    public function a_named_tax_from_another_company_is_refused(): void
    {
        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);
        $foreign = Tax::factory()->for($other)->create(['code' => 'VAT', 'tax_type' => TaxType::Output]);

        $this->expectException(ValidationException::class);

        $this->resolver->resolve($this->company, true, '2026-06-15', new Collection([$foreign->id]));
    }

    /**
     * Naming an OUTPUT tax on a purchase is a mistake worth naming: the user has
     * configured the tax and picked it on the wrong side of the ledger.
     */
    #[Test]
    public function a_tax_named_on_the_wrong_side_is_refused(): void
    {
        $output = $this->tax('VAT', TaxType::Output);

        $this->expectException(ValidationException::class);

        $this->resolver->resolve($this->company, false, '2026-06-15', new Collection([$output->id]));
    }

    /**
     * Resolution order is by code, so the same two taxes always produce the same
     * components in the same order no matter which was created first.
     */
    #[Test]
    public function taxes_are_resolved_in_a_deterministic_order(): void
    {
        $this->tax('ZED', TaxType::Output);
        $this->tax('CGST', TaxType::Output);
        $this->tax('ABED', TaxType::Output);

        $codes = $this->resolver->resolve($this->company, true, '2026-06-15')->pluck('code')->all();

        $this->assertSame(['ABED', 'CGST', 'ZED'], $codes);
    }

    /**
     * A tax with no rate in force is skipped when nothing named it, because a
     * document should not fail to post just because someone configured a tax and
     * never finished setting it up.
     */
    #[Test]
    public function a_tax_with_no_rate_on_the_date_is_skipped_when_it_was_not_named(): void
    {
        // Created without the helper's rate, so it is a configured tax nobody has
        // finished setting up.
        Tax::factory()->for($this->company)->create([
            'code' => 'FUTURE',
            'name' => 'Future Tax',
            'tax_type' => TaxType::Output,
        ]);

        $this->tax('VAT', TaxType::Output);

        $applicable = $this->resolver->applicableWithRates($this->company, true, '2026-06-15');

        $this->assertSame(['VAT'], $applicable->pluck('code')->all());
    }

    /**
     * Naming an unusable tax is a mistake, so it is refused rather than skipped.
     *
     * The refusal is deliberately not made by the resolver. The resolver's job is to
     * answer "which taxes apply", and this one does apply; what it cannot do is be
     * calculated. Reporting it at the point of calculation is what tells the user which
     * tax is misconfigured - a resolver that silently dropped it would calculate the
     * document at a different rate than the one asked for.
     */
    #[Test]
    public function a_tax_with_no_rate_is_refused_at_calculation_when_it_was_named(): void
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => 'FUTURE',
            'name' => 'Future Tax',
            'tax_type' => TaxType::Output,
        ]);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson('/api/accounting/tax/calculate', [
                'amount' => '100.0000',
                'date' => '2026-06-15',
                'tax_ids' => [$tax->getKey()],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_output_account_mapping_can_be_saved(): void
    {
        $tax = $this->tax('VAT', TaxType::Output);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}/account-mapping", [
                'output_account_id' => $this->salesLiability->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('data.output_account_id', $this->salesLiability->getKey())
            ->assertJsonPath('data.input_account_id', null);

        $this->assertDatabaseHas('tax_account_mappings', [
            'tax_id' => $tax->getKey(),
            'output_account_id' => $this->salesLiability->getKey(),
        ]);
    }

    /**
     * A tax collected on a sale is money owed to a tax authority, so it belongs on
     * a liability account. Accepting an asset or expense account here would let a
     * document post tax to an account that does not owe it, and the trial balance
     * would still balance.
     */
    #[Test]
    public function an_output_account_must_be_a_liability(): void
    {
        $tax = $this->tax('VAT', TaxType::Output);
        $expense = Account::factory()->for($this->company)->expense()->create([
            'code' => '5000',
            'name' => 'Office Supplies',
        ]);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}/account-mapping", [
                'output_account_id' => $expense->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('output_account_id');

        $this->assertDatabaseMissing('tax_account_mappings', ['tax_id' => $tax->getKey()]);
    }

    /**
     * Tax reclaimed on a purchase is money owed back, so it is an asset.
     */
    #[Test]
    public function an_input_account_must_be_an_asset(): void
    {
        $tax = $this->tax('VATIN', TaxType::Input);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}/account-mapping", [
                'input_account_id' => $this->salesLiability->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('input_account_id');
    }

    #[Test]
    public function an_account_from_another_company_is_refused(): void
    {
        $tax = $this->tax('VAT', TaxType::Output);

        $otherOwner = $this->createUserWithRole('Accountant');
        $other = $this->createCompanyFor($otherOwner);

        $foreign = Account::factory()->for($other)->liability()->create([
            'code' => '2199',
            'name' => 'Foreign Tax Payable',
        ]);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}/account-mapping", [
                'output_account_id' => $foreign->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('output_account_id');
    }

    /**
     * One mapping row per tax, so saving twice replaces rather than accumulates.
     */
    #[Test]
    public function saving_the_mapping_again_replaces_it(): void
    {
        $tax = $this->tax('VAT', TaxType::Both);

        $other = Account::factory()->for($this->company)->liability()->create([
            'code' => '2110',
            'name' => 'Other Tax Payable',
        ]);

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}/account-mapping", [
                'output_account_id' => $this->salesLiability->getKey(),
                'input_account_id' => $this->purchaseAsset->getKey(),
            ])
            ->assertOk();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->putJson("/api/accounting/taxes/{$tax->getKey()}/account-mapping", [
                'output_account_id' => $other->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('data.output_account_id', $other->getKey());

        $this->assertSame(1, TaxAccountMapping::query()->where('tax_id', $tax->getKey())->count());
        $this->assertNull(
            TaxAccountMapping::query()->where('tax_id', $tax->getKey())->value('input_account_id'),
            'An account omitted from the replacement should be cleared, not silently kept.'
        );
    }

    private function tax(string $code, TaxType $type): Tax
    {
        $tax = Tax::factory()->for($this->company)->create([
            'code' => $code,
            'name' => $code,
            'tax_type' => $type,
        ]);

        TaxRate::factory()->for($tax)->rate('10.0000')
            ->effectiveFrom(Carbon::parse('2020-01-01')->toDateString())
            ->create();

        return $tax;
    }
}
