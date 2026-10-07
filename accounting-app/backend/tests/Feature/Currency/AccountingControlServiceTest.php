<?php

namespace Tests\Feature\Currency;

use App\Enums\ControlStatus;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyFxSetting;
use App\Models\Currency;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Accounting\Controls\AccountingControlService;
use App\Services\Accounting\Controls\ControlFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The currency/FX accounting controls.
 *
 * These checks are read-only diagnostics, so the tests assert on what they report,
 * never on a side effect. The two things worth pinning down beyond "does it run" are:
 *
 *  1. A healthy company reports PASS for every control - a control that cannot be
 *     clean is a control nobody reads.
 *  2. The warnings are warnings, not failures: "the base currency can no longer be
 *     changed" and "foreign activity without FX accounts" are properties of a
 *     company, not corrupt data, and reporting them as FAIL would make a correct
 *     ledger look broken.
 */
class AccountingControlServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountingControlService $controls;

    private User $user;

    private Company $company;

    private Currency $base;

    private Currency $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controls = app(AccountingControlService::class);
        $this->user = $this->createUserWithRole(RoleName::Accountant);

        $this->base = Currency::factory()->code('USD')->create();
        $this->foreign = Currency::factory()->code('EUR')->create();

        $this->company = $this->createCompanyFor($this->user, ['currency_id' => $this->base->id]);
    }

    #[Test]
    public function a_healthy_company_passes_every_control(): void
    {
        $statuses = $this->statusesByControl($this->controls->run($this->company));

        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::BASE_CURRENCY]);
        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::BASE_CURRENCY_CHANGE_SAFETY]);
        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::FOREIGN_TRANSACTION_RATE]);
        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::INVALID_HISTORICAL_FX_STATE]);
        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::FOREIGN_SETTLEMENT_ACCOUNTS]);
        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::ACCOUNT_DOCUMENT_CURRENCY]);
    }

    #[Test]
    public function a_company_without_a_base_currency_warns_but_does_not_fail(): void
    {
        $company = $this->createCompanyFor($this->user, ['currency_id' => null]);

        $statuses = $this->statusesByControl($this->controls->run($company));

        $this->assertSame(ControlStatus::Warning, $statuses[AccountingControlService::BASE_CURRENCY]);
        $this->assertNotSame(ControlStatus::Fail, $statuses[AccountingControlService::BASE_CURRENCY]);
    }

    #[Test]
    public function an_inactive_base_currency_warns(): void
    {
        $this->base->forceFill(['is_active' => false])->save();

        $statuses = $this->statusesByControl($this->controls->run($this->company));

        $this->assertSame(ControlStatus::Warning, $statuses[AccountingControlService::BASE_CURRENCY]);
    }

    #[Test]
    public function posted_accounting_makes_a_base_currency_change_unsafe(): void
    {
        $this->postBaseJournal();

        $statuses = $this->statusesByControl($this->controls->run($this->company));

        $this->assertSame(ControlStatus::Warning, $statuses[AccountingControlService::BASE_CURRENCY_CHANGE_SAFETY]);
    }

    #[Test]
    public function foreign_activity_without_fx_accounts_warns(): void
    {
        $journal = $this->postBaseJournal();
        $this->addForeignLine($journal, Account::factory()->for($this->company)->asset()->create());

        $statuses = $this->statusesByControl($this->controls->run($this->company));

        $this->assertSame(ControlStatus::Warning, $statuses[AccountingControlService::FOREIGN_SETTLEMENT_ACCOUNTS]);
        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::FOREIGN_TRANSACTION_RATE]);
        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::INVALID_HISTORICAL_FX_STATE]);
    }

    #[Test]
    public function foreign_activity_with_configured_fx_accounts_passes(): void
    {
        CompanyFxSetting::factory()->configured($this->company)->create();

        $journal = $this->postBaseJournal();
        $this->addForeignLine($journal, Account::factory()->for($this->company)->asset()->create());

        $statuses = $this->statusesByControl($this->controls->run($this->company));

        $this->assertSame(ControlStatus::Pass, $statuses[AccountingControlService::FOREIGN_SETTLEMENT_ACCOUNTS]);
    }

    #[Test]
    public function a_foreign_line_posted_to_a_mismatched_account_fails(): void
    {
        $account = Account::factory()->for($this->company)->asset()->create([
            'currency_id' => $this->base->id,
        ]);

        $journal = $this->postBaseJournal();
        $this->addForeignLine($journal, $account);

        $findings = $this->controls->accountDocumentCurrencyFindings($this->company);

        $this->assertSame(ControlStatus::Fail, $findings[0]->status);
        $this->assertSame(AccountingControlService::ACCOUNT_DOCUMENT_CURRENCY, $findings[0]->controlCode);
    }

    /**
     * @param  list<ControlFinding>  $findings
     * @return array<string, ControlStatus>
     */
    private function statusesByControl(array $findings): array
    {
        $statuses = [];

        foreach ($findings as $finding) {
            $statuses[$finding->controlCode] = $finding->status;
        }

        return $statuses;
    }

    private function postBaseJournal(): Journal
    {
        $journal = Journal::factory()->for($this->company)->posted()->create();

        JournalLine::query()->forceCreate([
            'journal_id' => $journal->id,
            'account_id' => Account::factory()->for($this->company)->asset()->create()->id,
            'line_number' => 1,
            'debit' => '100.0000',
            'credit' => '0.0000',
        ]);

        return $journal;
    }

    private function addForeignLine(Journal $journal, Account $account): void
    {
        JournalLine::query()->forceCreate([
            'journal_id' => $journal->id,
            'account_id' => $account->id,
            'line_number' => 2,
            'currency_id' => $this->foreign->id,
            'foreign_debit' => '1000.0000',
            'exchange_rate' => '1.1000000000',
            'debit' => '1100.0000',
            'credit' => '0.0000',
        ]);
    }
}
