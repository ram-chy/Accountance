<?php

namespace Tests\Feature\Currency;

use App\Enums\JournalStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Accounting\Currency\CompanyCurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Choosing and changing a company's base currency.
 *
 * The rule this file exists to pin down is §25 of the phase brief: a base-currency
 * change must never silently reinterpret posted accounting data. Journal lines
 * store a bare number; the company row is what makes it mean a currency. Swapping
 * that row is therefore not a conversion, it is a relabelling of every posted
 * balance - and the service refuses it whenever the relabelled history exists.
 *
 * The positive case that is easy to get wrong is the FIRST choice: a company with
 * no base currency books at an implicit rate of 1, and assigning a currency to
 * that history renames it without moving a number, which is exactly the setup path
 * the no-seeder decision requires.
 */
class CompanyCurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    private CompanyCurrencyService $service;

    private User $user;

    private Company $company;

    private Currency $idr;

    private Currency $usd;

    private Currency $eur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CompanyCurrencyService::class);
        $this->user = $this->createUserWithRole(RoleName::Accountant);

        $this->idr = Currency::factory()->code('IDR')->create();
        $this->usd = Currency::factory()->code('USD')->create();
        $this->eur = Currency::factory()->code('EUR')->create();

        $this->company = $this->createCompanyFor($this->user, ['currency_id' => $this->idr->id]);
    }

    #[Test]
    public function it_changes_the_base_currency_when_no_posted_history_exists(): void
    {
        $updated = $this->service->changeBaseCurrency($this->company, $this->usd, $this->user);

        $this->assertSame($this->usd->id, $updated->currency_id);
        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'currency_id' => $this->usd->id,
        ]);
    }

    #[Test]
    public function it_audits_a_base_currency_change(): void
    {
        $this->service->changeBaseCurrency($this->company, $this->usd, $this->user);

        $log = AuditLog::query()
            ->where('auditable_type', $this->company->getMorphClass())
            ->where('auditable_id', $this->company->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->idr->id, $log->before_data['currency_id']);
        $this->assertSame($this->usd->id, $log->after_data['currency_id']);
    }

    #[Test]
    public function choosing_the_same_currency_is_a_no_op(): void
    {
        $updated = $this->service->changeBaseCurrency($this->company, $this->idr, $this->user);

        $this->assertSame($this->idr->id, $updated->currency_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[Test]
    public function first_time_assignment_is_allowed_even_after_base_documents_are_posted(): void
    {
        $company = $this->createCompanyFor($this->user, ['currency_id' => null]);

        $this->postBaseJournal($company);

        $updated = $this->service->changeBaseCurrency($company, $this->usd, $this->user);

        $this->assertSame($this->usd->id, $updated->currency_id);
    }

    #[Test]
    public function changing_an_established_base_with_posted_history_is_refused(): void
    {
        $this->postBaseJournal($this->company);

        try {
            $this->service->changeBaseCurrency($this->company, $this->usd, $this->user);
            $this->fail('Expected the base-currency change to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency_id', $e->errors());
        }

        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'currency_id' => $this->idr->id,
        ]);
    }

    #[Test]
    public function a_company_with_posted_foreign_activity_can_never_change_base(): void
    {
        $journal = $this->postBaseJournal($this->company);

        JournalLine::query()->forceCreate([
            'journal_id' => $journal->id,
            'account_id' => Account::factory()->for($this->company)->asset()->create()->id,
            'line_number' => 2,
            'currency_id' => $this->usd->id,
            'foreign_debit' => '1000.0000',
            'exchange_rate' => '1.1000000000',
            'debit' => '1100.0000',
            'credit' => '0.0000',
        ]);

        try {
            $this->service->changeBaseCurrency($this->company, $this->eur, $this->user);
            $this->fail('Expected the base-currency change to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('foreign', strtolower($e->errors()['currency_id'][0]));
        }
    }

    #[Test]
    public function draft_history_does_not_block_a_change(): void
    {
        Journal::factory()->for($this->company)->create(['status' => JournalStatus::Draft->value]);

        $updated = $this->service->changeBaseCurrency($this->company, $this->usd, $this->user);

        $this->assertSame($this->usd->id, $updated->currency_id);
    }

    #[Test]
    public function another_companys_posted_history_does_not_block_a_change(): void
    {
        $other = $this->createCompanyFor($this->user, ['currency_id' => $this->idr->id]);
        $this->postBaseJournal($other);

        $updated = $this->service->changeBaseCurrency($this->company, $this->usd, $this->user);

        $this->assertSame($this->usd->id, $updated->currency_id);
    }

    #[Test]
    public function an_inactive_currency_cannot_become_the_base_currency(): void
    {
        $inactive = Currency::factory()->code('GBP')->inactive()->create();

        try {
            $this->service->changeBaseCurrency($this->company, $inactive, $this->user);
            $this->fail('Expected an inactive currency to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency_id', $e->errors());
        }

        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'currency_id' => $this->idr->id,
        ]);
    }

    private function postBaseJournal(Company $company): Journal
    {
        $journal = Journal::factory()->for($company)->posted()->create();

        JournalLine::query()->forceCreate([
            'journal_id' => $journal->id,
            'account_id' => Account::factory()->for($company)->asset()->create()->id,
            'line_number' => 1,
            'debit' => '100.0000',
            'credit' => '0.0000',
        ]);

        return $journal;
    }
}
