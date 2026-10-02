<?php

namespace Tests\Feature\Accounting;

use App\Enums\TransactionStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\SalesInvoice;
use App\Models\Tax;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Effective-dated rates.
 *
 * The one rule this file exists to pin down is "a tax has exactly one rate in force
 * on any given day". Everything else here - precision, bounds, lifecycle - is in
 * service of that, because an ambiguous rate resolution is the one failure this
 * phase cannot tolerate: it would put a wrong figure on a posted document.
 */
class TaxRateServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private Company $company;

    private Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountant = $this->createUserWithRole('Accountant');
        $this->company = $this->createCompanyFor($this->accountant);

        $this->tax = Tax::factory()->for($this->company)->create([
            'code' => 'VAT',
            'name' => 'Value Added Tax',
        ]);
    }

    #[Test]
    public function a_rate_can_be_created(): void
    {
        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '20.0000',
                'effective_from' => '2026-01-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rate', '20.0000')
            ->assertJsonPath('data.effective_from', '2026-01-01')
            ->assertJsonPath('data.effective_to', null)
            ->assertJsonPath('data.is_open_ended', true)
            ->assertJsonPath('data.is_active', true);
    }

    /**
     * The rate is stored exactly as submitted.
     *
     * 7.5000 must come back as 7.5000 and not as a float that JSON has already
     * rounded. Asserted on the raw column so a cast cannot paper over it.
     */
    #[Test]
    public function the_rate_is_stored_as_an_exact_decimal(): void
    {
        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '7.5000',
                'effective_from' => '2026-01-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rate', '7.5000');

        $this->assertSame('7.5000', TaxRate::query()->value('rate'));
    }

    #[Test]
    public function a_rate_must_be_a_percentage_below_one_hundred(): void
    {
        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '100.0000',
                'effective_from' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '-1.0000',
                'effective_from' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');
    }

    /**
     * The CHECK constraint, not just the request layer.
     *
     * Asserted by writing through the factory, which bypasses every validation
     * rule. If this passes but the request test fails, the request layer is doing
     * the work; if this fails, the database is not.
     */
    #[Test]
    public function the_database_refuses_a_rate_of_one_hundred_percent(): void
    {
        $this->expectException(QueryException::class);

        TaxRate::factory()->for($this->tax)->rate('100.0000')->create();
    }

    #[Test]
    public function a_rate_must_not_end_before_it_starts(): void
    {
        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '10.0000',
                'effective_from' => '2026-06-01',
                'effective_to' => '2026-01-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('effective_to');
    }

    #[Test]
    public function two_periods_may_not_overlap(): void
    {
        TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2026-01-01')->effectiveTo('2026-06-30')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '12.0000',
                'effective_from' => '2026-06-01',
                'effective_to' => '2026-12-31',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');
    }

    /**
     * Open-ended means open-ended: a new rate cannot be laid over an existing one
     * that never ends, because both would answer for every day from their start.
     */
    #[Test]
    public function a_new_rate_may_not_overlap_an_open_ended_one(): void
    {
        TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2020-01-01')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '12.0000',
                'effective_from' => '2026-04-01',
                'effective_to' => '2026-12-31',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');
    }

    /**
     * A rejected rate must leave the existing history untouched.
     *
     * The overlap check runs inside the same transaction as the write, so a refusal
     * cannot leave a half-finished change behind. Asserted on both halves of that: the
     * existing rate keeps the end date it had, and no new row appears.
     *
     * The rejected rate here carries an explicit end date, which is what makes it a
     * rejection rather than a supersession - see the open-ended case above, where the
     * same overlap is resolved by closing the predecessor instead of refused.
     */
    #[Test]
    public function a_rejected_rate_leaves_existing_history_unchanged(): void
    {
        $existing = TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2020-01-01')->effectiveTo('2026-03-31')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '12.0000',
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');

        $existing->refresh();

        $this->assertSame('2026-03-31', $existing->effective_to->toDateString());
        $this->assertSame(1, TaxRate::query()->count());
    }

    /**
     * Changing a rate is the ordinary case: the company supersedes 10% with 12%
     * from April. Submitting the new rate with no end date closes the old one the
     * day before, which is what a user means by "from April it is 12%".
     */
    #[Test]
    public function a_new_open_ended_rate_closes_its_predecessor_the_day_before(): void
    {
        $existing = TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2020-01-01')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '12.0000',
                'effective_from' => '2026-04-01',
            ])
            ->assertCreated();

        $existing->refresh();

        $this->assertSame('2026-03-31', $existing->effective_to->toDateString());
    }

    /**
     * Consecutive periods that merely touch are not an overlap.
     */
    #[Test]
    public function adjacent_periods_are_accepted(): void
    {
        TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2020-01-01')->effectiveTo('2026-03-31')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->postJson("/api/accounting/taxes/{$this->tax->getKey()}/rates", [
                'rate' => '12.0000',
                'effective_from' => '2026-04-01',
            ])
            ->assertCreated();
    }

    /**
     * An inactive rate stays on file but does not answer for any date.
     */
    #[Test]
    public function an_inactive_rate_does_not_answer_for_a_date(): void
    {
        $rate = TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2020-01-01')->create();

        $this->assertNotNull($this->tax->rateOn('2026-06-15'));

        $rate->forceFill(['is_active' => false])->save();
        $this->tax->unsetRelation('rates');

        $this->assertNull($this->tax->rateOn('2026-06-15'));
    }

    #[Test]
    public function a_rate_can_be_deleted_while_it_is_the_only_one(): void
    {
        $rate = TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2026-01-01')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->deleteJson("/api/accounting/taxes/{$this->tax->getKey()}/rates/{$rate->getKey()}")
            ->assertOk();

        $this->assertDatabaseMissing('tax_rates', ['id' => $rate->getKey()]);
    }

    /**
     * A rate a posted document used cannot be deleted, only deactivated.
     */
    #[Test]
    public function a_rate_used_by_a_posted_document_cannot_be_deleted(): void
    {
        $rate = TaxRate::factory()->for($this->tax)->rate('10.0000')
            ->effectiveFrom('2020-01-01')->create();

        $this->createPostedDocumentUsing('10.0000');

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->deleteJson("/api/accounting/taxes/{$this->tax->getKey()}/rates/{$rate->getKey()}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');

        $this->assertDatabaseHas('tax_rates', ['id' => $rate->getKey()]);
    }

    /**
     * A rate belonging to a different tax in the same company must not be
     * re-parented by hitting this tax's rate routes.
     */
    #[Test]
    public function a_rate_from_another_tax_is_refused(): void
    {
        $other = Tax::factory()->for($this->company)->create(['code' => 'GST', 'name' => 'GST']);
        $rate = TaxRate::factory()->for($other)->rate('18.0000')
            ->effectiveFrom('2020-01-01')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->deleteJson("/api/accounting/taxes/{$this->tax->getKey()}/rates/{$rate->getKey()}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate');

        $this->assertDatabaseHas('tax_rates', [
            'id' => $rate->getKey(),
            'tax_id' => $other->getKey(),
        ]);
    }

    #[Test]
    public function rates_can_be_listed_for_a_tax(): void
    {
        TaxRate::factory()->for($this->tax)->rate('10.0000')->effectiveFrom('2020-01-01')->effectiveTo('2026-03-31')->create();
        TaxRate::factory()->for($this->tax)->rate('12.0000')->effectiveFrom('2026-04-01')->create();

        $this->actingAsJwt($this->accountant)
            ->withCompanyContext($this->company)
            ->getJson("/api/accounting/taxes/{$this->tax->getKey()}/rates")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.rate', '10.0000')
            ->assertJsonPath('data.1.rate', '12.0000');
    }

    /**
     * A posted invoice line carrying this tax's percentage.
     *
     * Built directly rather than through SalesInvoiceService because the point is
     * the *snapshot* that the service already wrote, not that the service writes
     * it - and because posting through the service requires a chart of accounts
     * this file has no interest in building. Only the two facts the rate service
     * actually consults matter here: a line carrying the percentage, and an
     * invoice past POSTED, which is what makes the date blocking.
     */
    private function createPostedDocumentUsing(string $percentage): void
    {
        $revenue = Account::factory()->for($this->company)->revenue()->create([
            'code' => '4000',
            'name' => 'Sales Revenue',
        ]);

        $taxAccount = Account::factory()->for($this->company)->liability()->create([
            'code' => '2100',
            'name' => 'Tax Payable',
        ]);

        $invoice = SalesInvoice::factory()->for($this->company)->create([
            'subtotal' => '100.0000',
            'tax_total' => '10.0000',
            'grand_total' => '110.0000',
            'tax_account_id' => $taxAccount->getKey(),
            'status' => TransactionStatus::Posted,
            'journal_id' => Journal::factory()->for($this->company)->onDate('2026-06-15')->posted()->create()->getKey(),
        ]);

        $invoice->lines()->create([
            'line_number' => 1,
            'description' => 'Taxed sale',
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'discount' => '0.0000',
            'tax_rate' => $percentage,
            'tax_amount' => '10.0000',
            'line_total' => '110.0000',
            'tax_id' => $this->tax->getKey(),
            'revenue_account_id' => $revenue->getKey(),
        ]);
    }
}
