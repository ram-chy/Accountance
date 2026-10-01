<?php

namespace Tests\Feature\Accounting;

use App\Enums\CashBankKind;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\CashBankTransaction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tenant isolation for cash and bank.
 *
 * Two independent mechanisms are in play, and this file separates them because
 * they fail differently:
 *
 *  1. Route model binding. A CashBankTransaction or Account resolved by the
 *     `{transaction}` / `{account}` parameter is already known to belong to the
 *     active company, because AppServiceProvider scoped those bindings. A
 *     cross-company id therefore 404s *before* the controller runs.
 *
 *  2. Explicit company predicates. The listing query, and the resolver used when
 *     creating a transaction, both filter on company_id themselves, because they
 *     are not going through a route binding to get one.
 *
 * The property that matters most is that both mechanisms must agree. If only one
 * existed, a carefully chosen id would still cross the boundary - so each test
 * below states which mechanism it is exercising.
 *
 * The account-configuration endpoints are the interesting case, because they act
 * on Account, which was already scoped in Phase 4. Phase 7 reuses that binding
 * rather than introducing a second one for the same model, and these tests confirm
 * the reuse did not weaken it.
 */
class CashBankCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    /** The user whose context the test is currently acting in. */
    private User $user;

    /** That user's company. */
    private Company $company;

    /** Cash and bank accounts belonging to $company. */
    private array $accounts;

    /**
     * Create a user, a company for them, and a cash/bank chart for it.
     *
     * Returns the triple rather than only the company because a tenant is
     * inseparable: every request in these tests needs both the user to
     * authenticate as and the company to switch into, and deriving one from the
     * other would be a guess about which relations exist.
     *
     * @return array{0: User, 1: Company, 2: array<string, Account>}
     */
    private function tenant(): array
    {
        $user = $this->createUserWithRole(RoleName::Accountant);
        $company = $this->createCompanyFor($user);

        return [
            $user,
            $company,
            [
                'cash' => Account::factory()->for($company)->cash()->create([
                    'code' => '1010',
                    'name' => 'Cash',
                ]),
                'bank' => Account::factory()->for($company)->bank()->create([
                    'code' => '1020',
                    'name' => 'Bank',
                ]),
            ],
        ];
    }

    /**
     * The same, but also adopted as the current context for convenience.
     *
     * @return array{0: User, 1: Company, 2: array<string, Account>}
     */
    private function currentTenant(): array
    {
        [$user, $company, $accounts] = $this->tenant();

        $this->user = $user;
        $this->company = $company;
        $this->accounts = $accounts;

        return [$user, $company, $accounts];
    }

    /**
     * A draft transfer belonging to the given company, created through the API by
     * a member of that company.
     *
     * @param  array<string, Account>  $accounts
     */
    private function draftFor(User $user, Company $company, array $accounts): CashBankTransaction
    {
        $this->makePeriodFor($company, '2027-06-01', 'June 2027');

        $response = $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson('/api/cash-bank-transactions/transfers', [
                'transaction_date' => '2027-06-01',
                'amount' => '75.0000',
                'source_account_id' => $accounts['bank']->getKey(),
                'destination_account_id' => $accounts['cash']->getKey(),
            ]);

        $response->assertCreated();

        return CashBankTransaction::query()->whereKey($response->json('data.id'))->firstOrFail();
    }

    /*
     * ---------------------------------------------------------------------
     * Route model binding
     * ---------------------------------------------------------------------
     */

    /**
     * Another company's transaction is not addressable, in any direction of the
     * lifecycle - not just on read.
     */
    #[Test]
    public function another_companys_transaction_is_not_addressable(): void
    {
        [$theirUser, $theirCompany, $theirAccounts] = $this->tenant();
        $transaction = $this->draftFor($theirUser, $theirCompany, $theirAccounts);

        [$intruder, $intruderCompany] = $this->currentTenant();

        // Every single-member route must refuse it, not just the read.
        $this->actingAsJwt($intruder)
            ->withCompanyContext($intruderCompany)
            ->getJson("/api/cash-bank-transactions/{$transaction->getKey()}")
            ->assertNotFound();

        $this->actingAsJwt($intruder)
            ->withCompanyContext($intruderCompany)
            ->putJson("/api/cash-bank-transactions/{$transaction->getKey()}", ['amount' => '1.0000'])
            ->assertNotFound();

        $this->actingAsJwt($intruder)
            ->withCompanyContext($intruderCompany)
            ->deleteJson("/api/cash-bank-transactions/{$transaction->getKey()}")
            ->assertNotFound();

        $this->actingAsJwt($intruder)
            ->withCompanyContext($intruderCompany)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertNotFound();

        $transaction->refresh();

        $this->assertSame('DRAFT', $transaction->status->value, 'None of those requests may have changed it.');
        $this->assertSame('75.0000', $transaction->amount);
        $this->assertNull($transaction->journal_id);
    }

    /**
     * Another company's account cannot be classified, and its bank details cannot
     * be touched - the Phase 4 `{account}` binding is reused, not weakened.
     */
    #[Test]
    public function another_companys_account_cannot_be_reclassified(): void
    {
        [$theirUser, $theirCompany] = $this->tenant();

        $victim = Account::factory()->for($theirCompany)->asset()->create([
            'code' => '1100',
            'name' => 'Receivable',
        ]);

        [$intruder, $intruderCompany] = $this->currentTenant();

        $this->actingAsJwt($intruder)
            ->withCompanyContext($intruderCompany)
            ->putJson("/api/cash-bank-accounts/{$victim->getKey()}", [
                'cash_bank_kind' => CashBankKind::Cash->value,
            ])
            ->assertNotFound();

        $this->actingAsJwt($intruder)
            ->withCompanyContext($intruderCompany)
            ->deleteJson("/api/cash-bank-accounts/{$victim->getKey()}/bank-details")
            ->assertNotFound();

        $this->actingAsJwt($intruder)
            ->withCompanyContext($intruderCompany)
            ->postJson("/api/cash-bank-accounts/{$victim->getKey()}/deactivate")
            ->assertNotFound();

        $this->assertNull($victim->refresh()->cash_bank_kind);
    }

    /*
     * ---------------------------------------------------------------------
     * Explicit company predicates
     * ---------------------------------------------------------------------
     */

    /**
     * The listing shows only the active company's transactions.
     */
    #[Test]
    public function the_listing_never_shows_another_companys_transactions(): void
    {
        [$mineUser, $mineCompany, $mineAccounts] = $this->currentTenant();
        $this->draftFor($mineUser, $mineCompany, $mineAccounts);

        [$theirUser, $theirCompany, $theirAccounts] = $this->tenant();
        $theirs = $this->draftFor($theirUser, $theirCompany, $theirAccounts);

        $response = $this->actingAsJwt($mineUser)
            ->withCompanyContext($mineCompany)
            ->getJson('/api/cash-bank-transactions');

        $response->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertFalse(
            $ids->contains($theirs->getKey()),
            "Another tenant's transaction appeared in the listing."
        );
    }

    /**
     * The account listing shows only the active company's cash/bank accounts.
     */
    #[Test]
    public function the_account_listing_never_shows_another_companys_accounts(): void
    {
        [$mineUser, $mineCompany] = array_slice($this->currentTenant(), 0, 2);

        [, , $theirAccounts] = $this->tenant();

        $response = $this->actingAsJwt($mineUser)
            ->withCompanyContext($mineCompany)
            ->getJson('/api/cash-bank-accounts');

        $response->assertSuccessful();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertFalse($ids->contains($theirAccounts['cash']->getKey()));
        $this->assertFalse($ids->contains($theirAccounts['bank']->getKey()));
        $this->assertCount(2, $ids, "Only this company's own two accounts should be listed.");
    }

    /**
     * A transaction cannot be created against another company's accounts.
     *
     * This one does not rely on route binding - no account appears in the path -
     * so it is the resolver's company predicate doing the work, and the error must
     * not distinguish "no such account" from "another company's".
     */
    #[Test]
    public function a_transaction_cannot_be_created_against_another_companys_accounts(): void
    {
        [$mineUser, $mineCompany] = array_slice($this->currentTenant(), 0, 2);

        [, , $theirAccounts] = $this->tenant();

        $response = $this->actingAsJwt($mineUser)
            ->withCompanyContext($mineCompany)
            ->postJson('/api/cash-bank-transactions/transfers', [
                'transaction_date' => '2027-06-01',
                'amount' => '75.0000',
                'source_account_id' => $theirAccounts['bank']->getKey(),
                'destination_account_id' => $theirAccounts['cash']->getKey(),
            ]);

        $response->assertStatus(422);

        $errors = $this->responseErrors($response);

        $this->assertArrayHasKey('source_account_id', $errors);
        $this->assertArrayHasKey('destination_account_id', $errors);

        // The message must not confirm that the account exists somewhere.
        $this->assertStringNotContainsString(
            'not a cash or bank account',
            $errors['source_account_id'][0],
            'A cross-company id must not be diagnosed as a classification problem - that would confirm it exists.'
        );

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    /**
     * Filtering by account id cannot be used to probe another tenant's data. The
     * filter validates existence within the active company, so a foreign id is
     * rejected outright rather than silently returning an empty page.
     */
    #[Test]
    public function the_account_filter_cannot_reference_another_company(): void
    {
        [$mineUser, $mineCompany, $mineAccounts] = $this->currentTenant();
        $mine = $this->draftFor($mineUser, $mineCompany, $mineAccounts);

        [$theirUser, $theirCompany, $theirAccounts] = $this->tenant();
        $this->draftFor($theirUser, $theirCompany, $theirAccounts);

        // Filtering by my own account works and returns only my transaction.
        $ok = $this->actingAsJwt($mineUser)
            ->withCompanyContext($mineCompany)
            ->getJson("/api/cash-bank-transactions?account_id={$mineAccounts['bank']->getKey()}");

        $ok->assertSuccessful();
        $this->assertSame(
            [$mine->getKey()],
            collect($ok->json('data'))->pluck('id')->all()
        );

        // Filtering by their account is refused, not silently ignored.
        $this->actingAsJwt($mineUser)
            ->withCompanyContext($mineCompany)
            ->getJson("/api/cash-bank-transactions?account_id={$theirAccounts['bank']->getKey()}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    /**
     * Posting is company-scoped too: a user cannot post their own company's
     * transaction while switched to another company's context.
     */
    #[Test]
    public function a_transaction_cannot_be_posted_from_another_company_context(): void
    {
        [$user, $mineCompany, $mineAccounts] = $this->currentTenant();
        $transaction = $this->draftFor($user, $mineCompany, $mineAccounts);

        // A second company the same user legitimately belongs to.
        $otherCompany = $this->createUnrelatedCompany();
        $this->addMemberTo($otherCompany, $user);

        $this->actingAsJwt($user)
            ->withCompanyContext($otherCompany)
            ->postJson("/api/cash-bank-transactions/{$transaction->getKey()}/post")
            ->assertNotFound();

        $transaction->refresh();

        $this->assertSame('DRAFT', $transaction->status->value);
        $this->assertDatabaseCount('journals', 0);
    }

    /**
     * A transaction always carries its creator's active company. There is no
     * payload through which to file a movement under a different tenant, so the
     * column is force-filled rather than read from the request.
     */
    #[Test]
    public function a_transaction_always_belongs_to_the_active_company(): void
    {
        [$mineUser, $mineCompany, $mineAccounts] = $this->currentTenant();

        [, $theirCompany] = $this->tenant();

        $response = $this->actingAsJwt($mineUser)
            ->withCompanyContext($mineCompany)
            ->postJson('/api/cash-bank-transactions/transfers', [
                'transaction_date' => '2027-06-01',
                'amount' => '5.0000',
                'source_account_id' => $mineAccounts['bank']->getKey(),
                'destination_account_id' => $mineAccounts['cash']->getKey(),
                'company_id' => $theirCompany->getKey(),
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('cash_bank_transactions', [
            'id' => $response->json('data.id'),
            'company_id' => $mineCompany->getKey(),
        ]);

        $this->assertDatabaseMissing('cash_bank_transactions', [
            'company_id' => $theirCompany->getKey(),
        ]);
    }

    /**
     * Document numbering is scoped per company, so two tenants may legitimately
     * both hold the same number. This is asserted because the opposite -
     * a global sequence - would be a cross-tenant information leak and a
     * predictable-id problem at the same time.
     */
    #[Test]
    public function two_companies_number_their_transactions_independently(): void
    {
        [$mineUser, $mineCompany, $mineAccounts] = $this->currentTenant();
        $mine = $this->draftFor($mineUser, $mineCompany, $mineAccounts);

        [$theirUser, $theirCompany, $theirAccounts] = $this->tenant();
        $theirs = $this->draftFor($theirUser, $theirCompany, $theirAccounts);

        $this->assertSame(
            $mine->transaction_number,
            $theirs->transaction_number,
            'Document numbers are scoped per company, so two tenants may both hold CBN-000001.'
        );
    }
}
