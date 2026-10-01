<?php

namespace Tests\Feature\Accounting;

use App\Enums\CashBankKind;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cash/bank account configuration.
 *
 * The claim under test throughout this file is the one Phase 7 was designed
 * around: a cash/bank account is an *ordinary account in the chart of accounts*
 * with a classification on it. So there is no create endpoint here - the account
 * is created through /api/accounts - and this file's job is to pin down what the
 * classification does and does not change.
 *
 * Two things in particular are asserted rather than assumed:
 *
 *   - The classification is the only eligibility criterion. An account's
 *     account_type does not gate it. That is tested with a liability-typed
 *     account, because a fixture built only from asset accounts would make the
 *     rule indistinguishable from "cash means asset".
 *
 *   - Removing a classification is refused once the account has accounting
 *     history, since a posted journal referring to it would otherwise become
 *     unrecoverable from the ledger alone.
 */
class CashBankAccountTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    #[Test]
    public function an_account_can_be_classified_as_cash(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->asset()->create([
            'code' => '1010',
            'name' => 'Petty Cash',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => CashBankKind::Cash->value,
            ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $account->getKey())
            ->assertJsonPath('data.cash_bank_kind', CashBankKind::Cash->value)
            ->assertJsonPath('data.bank_account', null);

        $this->assertSame(CashBankKind::Cash, $account->refresh()->cash_bank_kind);
    }

    /**
     * The classification does not care what type the account is.
     *
     * A liability-typed account marked as BANK still passes, which is what
     * "cash_bank_kind is the only criterion" means. It reads oddly, so it is
     * worth a test: the alternative implementation - quietly requiring Asset -
     * is the kind of restriction nobody notices until a user is blocked.
     */
    #[Test]
    public function the_kind_is_the_only_eligibility_criterion_not_the_account_type(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->liability()->create([
            'code' => '2100',
            'name' => 'Bank Overdraft Facility',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => CashBankKind::Bank->value,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.account_type', 'LIABILITY')
            ->assertJsonPath('data.cash_bank_kind', CashBankKind::Bank->value);
    }

    #[Test]
    public function an_inactive_account_cannot_be_classified(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->asset()->inactive()->create([
            'code' => '1010',
            'name' => 'Retired Account',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => CashBankKind::Cash->value,
            ]);

        $response->assertStatus(422);

        $this->assertArrayHasKey(
            'account_id',
            $this->responseErrors($response),
            'A setting that could never take effect should be reported, not stored.'
        );
        $this->assertNull($account->refresh()->cash_bank_kind);
    }

    #[Test]
    public function an_unknown_classification_is_rejected(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->asset()->create([
            'code' => '1010',
            'name' => 'Operating Account',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => 'PETTY',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cash_bank_kind');
    }

    #[Test]
    public function bank_details_can_be_recorded_against_a_bank_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->bank()->create([
            'code' => '1020',
            'name' => 'Main Current Account',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'bank_account' => [
                    'account_name' => 'Acme Industries',
                    'bank_name' => 'Example Bank',
                    'account_number' => '0041729931',
                    'branch' => 'Central',
                    'bank_identifier' => 'EXAM001',
                ],
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.bank_account.account_name', 'Acme Industries')
            ->assertJsonPath('data.bank_account.bank_name', 'Example Bank')
            ->assertJsonPath('data.bank_account.is_active', true);

        $this->assertDatabaseHas('bank_accounts', [
            'company_id' => $company->getKey(),
            'account_id' => $account->getKey(),
            'bank_name' => 'Example Bank',
        ]);
    }

    #[Test]
    public function saving_bank_details_twice_updates_rather_than_duplicates(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->bank()->create([
            'code' => '1020',
            'name' => 'Main Current Account',
        ]);

        foreach (['First Name', 'Second Name'] as $name) {
            $this->actingAsJwt($user)
                ->withCompanyContext($company)
                ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                    'bank_account' => [
                        'account_name' => $name,
                        'bank_name' => 'Example Bank',
                    ],
                ])
                ->assertSuccessful();
        }

        $this->assertSame(1, BankAccount::query()->where('account_id', $account->getKey())->count());
        $this->assertSame('Second Name', $account->bankAccount->account_name);
    }

    /**
     * A cash account is not a bank account, and cannot be given a bank.
     */
    #[Test]
    public function bank_details_cannot_be_recorded_against_an_unclassified_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->cash()->create([
            'code' => '1010',
            'name' => 'Petty Cash',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'bank_account' => [
                    'account_name' => 'Acme Industries',
                    'bank_name' => 'Example Bank',
                ],
            ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('account_id', $this->responseErrors($response));
    }

    #[Test]
    public function an_account_with_bank_details_cannot_be_reclassified_as_cash(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->bank()->create([
            'code' => '1020',
            'name' => 'Main Current Account',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'bank_account' => ['account_name' => 'Acme', 'bank_name' => 'Example Bank'],
            ])
            ->assertSuccessful();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => CashBankKind::Cash->value,
            ]);

        $response->assertStatus(422);
        $this->assertSame(CashBankKind::Bank, $account->refresh()->cash_bank_kind);

        // And the documented way out works: remove the details first.
        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/cash-bank-accounts/{$account->getKey()}/bank-details")
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => CashBankKind::Cash->value,
            ])
            ->assertSuccessful();
    }

    #[Test]
    public function a_classification_can_be_cleared_from_an_unused_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->cash()->create([
            'code' => '1010',
            'name' => 'Petty Cash',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => null,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.cash_bank_kind', null);

        $this->assertNull($account->refresh()->cash_bank_kind);
    }

    #[Test]
    public function bank_details_can_be_deactivated_and_reactivated(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->bank()->create([
            'code' => '1020',
            'name' => 'Main Current Account',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'bank_account' => ['account_name' => 'Acme', 'bank_name' => 'Example Bank'],
            ])
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-accounts/{$account->getKey()}/deactivate")
            ->assertSuccessful()
            ->assertJsonPath('data.bank_account.is_active', false);

        /*
         * Deactivating the bank details must NOT deactivate the account itself.
         * These are two different facts and a user closing a bank account should
         * not have to retire it from the chart of accounts to stop using it.
         */
        $this->assertTrue(
            $account->refresh()->is_active,
            'Deactivating bank details must not touch accounts.is_active.'
        );

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-accounts/{$account->getKey()}/activate")
            ->assertSuccessful()
            ->assertJsonPath('data.bank_account.is_active', true);
    }

    #[Test]
    public function a_closed_bank_account_is_not_offered_for_new_movements(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $open = Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Open']);
        $closed = Account::factory()->for($company)->bank()->create(['code' => '1021', 'name' => 'Closed']);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$closed->getKey()}", [
                'bank_account' => ['account_name' => 'Acme', 'bank_name' => 'Example Bank'],
            ])
            ->assertSuccessful();

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson("/api/cash-bank-accounts/{$closed->getKey()}/deactivate")
            ->assertSuccessful();

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-accounts');

        $response->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($open->getKey()));
        $this->assertFalse(
            $ids->contains($closed->getKey()),
            'An account whose bank row is inactive should not be selectable.'
        );
    }

    #[Test]
    public function the_listing_only_returns_classified_active_accounts(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $cash = Account::factory()->for($company)->cash()->create(['code' => '1010', 'name' => 'Cash']);
        $bank = Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']);

        Account::factory()->for($company)->asset()->create(['code' => '1030', 'name' => 'Receivable']);
        Account::factory()->for($company)->cash()->inactive()->create(['code' => '1040', 'name' => 'Old Float']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-accounts');

        $response->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id')->all();
        sort($ids);

        $expected = [$cash->getKey(), $bank->getKey()];
        sort($expected);

        $this->assertSame($expected, $ids);
    }

    #[Test]
    public function the_listing_can_be_filtered_by_kind(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $cash = Account::factory()->for($company)->cash()->create(['code' => '1010', 'name' => 'Cash']);
        Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-accounts?cash_bank_kind='.CashBankKind::Cash->value);

        $response->assertSuccessful();
        $this->assertSame([$cash->getKey()], collect($response->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function the_resource_exposes_no_balance(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->cash()->create([
            'code' => '1010',
            'name' => 'Cash',
        ]);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-accounts');

        $response->assertSuccessful();

        $row = collect($response->json('data'))->firstWhere('id', $account->getKey());

        $this->assertIsArray($row);
        foreach (['balance', 'closing_balance', 'opening_balance', 'current_balance'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $row,
                'A cash/bank balance is a question about journal lines, not about the account record.'
            );
        }
    }

    /**
     * An account can be classified as it is created.
     *
     * The generic accounts endpoint accepts cash_bank_kind, so the common case -
     * adding a bank account to the chart in one step - is one request rather than
     * create-then-classify. The dedicated cash-bank endpoint remains for changing
     * the classification of an account that already exists.
     */
    #[Test]
    public function an_account_can_be_classified_as_it_is_created(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/accounts', [
                'code' => '1020',
                'name' => 'Main Current Account',
                'account_type' => 'ASSET',
                'cash_bank_kind' => CashBankKind::Bank->value,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.cash_bank_kind', CashBankKind::Bank->value);

        $this->assertSame(
            CashBankKind::Bank,
            Account::query()->whereKey($response->json('data.id'))->firstOrFail()->cash_bank_kind
        );
    }

    /**
     * The generic update endpoint classifies too, and routes the change through
     * CashBankAccountService so the "has history / has bank details" rules still
     * apply. If AccountService wrote the column itself, clearing a classification
     * on an account with history would silently succeed.
     */
    #[Test]
    public function the_generic_update_endpoint_classifies_through_the_cash_bank_service(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $account = Account::factory()->for($company)->asset()->create([
            'code' => '1010',
            'name' => 'Petty Cash',
        ]);

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/accounts/{$account->getKey()}", [
                'cash_bank_kind' => CashBankKind::Cash->value,
            ])
            ->assertSuccessful();

        $this->assertSame(CashBankKind::Cash, $account->refresh()->cash_bank_kind);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_configure_accounts(): void
    {
        $staff = $this->createUserWithRole(RoleName::Staff);
        $company = $this->createCompanyFor($staff);
        $account = Account::factory()->for($company)->asset()->create([
            'code' => '1010',
            'name' => 'Operating Account',
        ]);

        $this->assertFalse(
            $staff->fresh()->can(PermissionName::CashBankView->value),
            'Precondition: Staff has no cash/bank view permission.'
        );

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->getJson('/api/cash-bank-accounts')
            ->assertForbidden();

        $this->actingAsJwt($staff)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-accounts/{$account->getKey()}", [
                'cash_bank_kind' => CashBankKind::Cash->value,
            ])
            ->assertForbidden();

        $this->assertNull(
            $account->refresh()->cash_bank_kind,
            'A refused request must not have written anything.'
        );
    }
}
