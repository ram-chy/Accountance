<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Enums\CashBankKind;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    private function createCompanyWithUser(User $user): Company
    {
        return $this->createCompanyFor($user);
    }

    private function setupBankAccount(Company $company): array
    {
        $bankAccount = Account::factory()->for($company)->create([
            'name' => 'Bank Account',
            'code' => '1020',
            'account_type' => 'ASSET',
            'cash_bank_kind' => CashBankKind::Bank,
            'is_active' => true,
        ]);

        $bankMeta = BankAccount::factory()->for($company)->forAccount($bankAccount)->create([
            'account_number' => '123456',
            'bank_name' => 'Test Bank',
            'is_active' => true,
        ]);

        return [$bankAccount, $bankMeta];
    }

    #[Test]
    public function it_requires_bank_account_to_create_reconciliation(): void
    {
        $accountant = $this->accountant();
        $company = $this->createCompanyWithUser($accountant);

        $response = $this->actingAs($accountant, 'api')
            ->withCompanyContext($company)
            ->postJson('/api/bank-reconciliations', [
                'bank_account_id' => 999,
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'statement_opening_balance' => '10000.0000',
                'statement_closing_balance' => '10000.0000',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_creates_a_draft_reconciliation(): void
    {
        $accountant = $this->accountant();
        $company = $this->createCompanyWithUser($accountant);
        [$bankAccount, $bankMeta] = $this->setupBankAccount($company);

        $response = $this->actingAs($accountant, 'api')
            ->withCompanyContext($company)
            ->postJson('/api/bank-reconciliations', [
                'bank_account_id' => $bankMeta->getKey(),
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'statement_opening_balance' => '10000.0000',
                'statement_closing_balance' => '10000.0000',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'DRAFT');
    }

    #[Test]
    public function it_rejects_cash_account_for_reconciliation(): void
    {
        $accountant = $this->accountant();
        $company = $this->createCompanyWithUser($accountant);
        $cash = Account::factory()->for($company)->cash()->create(['name' => 'Cash']);
        $bankMeta = BankAccount::factory()->for($company)->forAccount($cash)->create(['is_active' => true]);

        $response = $this->actingAs($accountant, 'api')
            ->withCompanyContext($company)
            ->postJson('/api/bank-reconciliations', [
                'bank_account_id' => $bankMeta->getKey(),
                'from_date' => '2026-10-01',
                'to_date' => '2026-10-31',
                'statement_opening_balance' => '0.0000',
                'statement_closing_balance' => '0.0000',
            ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function it_rejects_backwards_dates(): void
    {
        $accountant = $this->accountant();
        $company = $this->createCompanyWithUser($accountant);
        [$bankAccount, $bankMeta] = $this->setupBankAccount($company);

        $response = $this->actingAs($accountant, 'api')
            ->withCompanyContext($company)
            ->postJson('/api/bank-reconciliations', [
                'bank_account_id' => $bankMeta->getKey(),
                'from_date' => '2026-10-31',
                'to_date' => '2026-10-01',
                'statement_opening_balance' => '0.0000',
                'statement_closing_balance' => '0.0000',
            ]);

        $response->assertStatus(422);
    }
}
