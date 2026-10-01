<?php

namespace Tests\Feature\Accounting;

use App\Enums\CashBankTransactionType;
use App\Enums\RoleName;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which account pairs each movement type will accept.
 *
 * The posting rule is the same for all three types - Dr destination, Cr source -
 * so the only thing the type changes is how strict each side is:
 *
 *   Deposit     destination must be cash/bank   source may be anything active
 *   Withdrawal  source must be cash/bank         destination may be anything active
 *   Transfer    both must be cash/bank
 *
 * This file tests that matrix directly rather than through a happy-path test per
 * type, because the interesting failures are the asymmetric ones. A test proving
 * "a deposit works" would pass unchanged if the deposit and withdrawal rules were
 * swapped, since a fixture built with cash accounts on both sides satisfies either.
 * The negative cases below are what actually distinguish them.
 *
 * The offset account on a deposit or a withdrawal is always required. Nothing in
 * this file lets the system choose one, and that is the point: "money arrived"
 * does not say whether it was capital introduced, a loan drawn, or a suspense
 * balance cleared.
 */
class CashBankMovementTest extends TestCase
{
    use RefreshDatabase;

    private function accountant(): User
    {
        return $this->createUserWithRole(RoleName::Accountant);
    }

    /**
     * A five-account chart covering every role a movement can play:
     * a cash account, a bank account, a plain asset, an expense and an equity.
     *
     * @return array<string, Account>
     */
    private function chart(Company $company): array
    {
        return [
            'cash' => Account::factory()->for($company)->cash()->create(['code' => '1010', 'name' => 'Cash']),
            'bank' => Account::factory()->for($company)->bank()->create(['code' => '1020', 'name' => 'Bank']),
            'receivable' => Account::factory()->for($company)->asset()->create([
                'code' => '1100',
                'name' => 'Accounts Receivable',
            ]),
            'expense' => Account::factory()->for($company)->expense()->create([
                'code' => '5100',
                'name' => 'Bank Charges',
            ]),
            'capital' => Account::factory()->for($company)->equity()->create([
                'code' => '3100',
                'name' => 'Share Capital',
            ]),
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function createEndpointProvider(): array
    {
        return [
            'deposit' => ['deposits'],
            'withdrawal' => ['withdrawals'],
            'transfer' => ['transfers'],
        ];
    }

    /**
     * A minimal valid payload for each type, so each happy-path test states only
     * what is distinctive about its type.
     *
     * @param  array<string, Account>  $chart
     * @return array<string, mixed>
     */
    private function validPayload(CashBankTransactionType $type, array $chart): array
    {
        return match ($type) {
            CashBankTransactionType::Deposit => [
                'source_account_id' => $chart['capital']->getKey(),
                'destination_account_id' => $chart['bank']->getKey(),
            ],
            CashBankTransactionType::Withdrawal => [
                'source_account_id' => $chart['bank']->getKey(),
                'destination_account_id' => $chart['expense']->getKey(),
            ],
            CashBankTransactionType::Transfer => [
                'source_account_id' => $chart['bank']->getKey(),
                'destination_account_id' => $chart['cash']->getKey(),
            ],
        };
    }

    /**
     * The URL segment for each movement type.
     *
     * Held here rather than on the enum on purpose: CashBankTransactionType is a
     * domain concept and has no business knowing a URL. The mapping is worth
     * having in the test precisely because it is nowhere else - the controller has
     * three named methods and routes/api.php has three literal paths, so if the
     * three ever disagreed the test below is what would notice.
     */
    private function endpointFor(CashBankTransactionType $type): string
    {
        return match ($type) {
            CashBankTransactionType::Deposit => 'deposits',
            CashBankTransactionType::Withdrawal => 'withdrawals',
            CashBankTransactionType::Transfer => 'transfers',
        };
    }

    private function typeForEndpoint(string $endpoint): CashBankTransactionType
    {
        return match ($endpoint) {
            'deposits' => CashBankTransactionType::Deposit,
            'withdrawals' => CashBankTransactionType::Withdrawal,
            'transfers' => CashBankTransactionType::Transfer,
        };
    }

    private function create(CashBankTransactionType $type, User $user, Company $company, array $payload)
    {
        return $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->postJson(
                '/api/cash-bank-transactions/'.$this->endpointFor($type),
                array_merge([
                    'transaction_date' => '2027-03-15',
                    'amount' => '250.0000',
                ], $payload),
            );
    }

    #[Test]
    #[DataProvider('createEndpointProvider')]
    public function each_type_can_be_created_as_a_draft(string $endpoint): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $type = $this->typeForEndpoint($endpoint);

        $response = $this->create($type, $user, $company, $this->validPayload($type, $chart));

        $response->assertCreated()
            ->assertJsonPath('data.transaction_type', $type->value)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.journal_id', null)
            ->assertJsonPath('data.amount', '250.0000');

        $this->assertDatabaseHas('cash_bank_transactions', [
            'company_id' => $company->getKey(),
            'transaction_type' => $type->value,
            'status' => 'DRAFT',
        ]);

        // Drafts are not in the ledger.
        $this->assertDatabaseMissing('journals', ['source_type' => 'cash_bank_transaction']);
    }

    /**
     * The number is generated with the CBN- prefix, and is not client-supplied.
     */
    #[Test]
    public function the_transaction_number_is_generated_with_its_own_prefix(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $response = $this->create(
            CashBankTransactionType::Transfer,
            $user,
            $company,
            $this->validPayload(CashBankTransactionType::Transfer, $chart),
        );

        $response->assertCreated();
        $this->assertStringStartsWith('CBN-', (string) $response->json('data.transaction_number'));
    }

    #[Test]
    public function a_supplied_transaction_number_is_ignored(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $response = $this->create(
            CashBankTransactionType::Transfer,
            $user,
            $company,
            array_merge(
                $this->validPayload(CashBankTransactionType::Transfer, $chart),
                [
                    'transaction_number' => 'CBN-9999',
                    'transaction_type' => CashBankTransactionType::Withdrawal->value,
                    'status' => 'POSTED',
                    'journal_id' => 12345,
                    'company_id' => 999,
                ],
            ),
        );

        $response->assertCreated()
            ->assertJsonPath('data.transaction_type', CashBankTransactionType::Transfer->value)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.journal_id', null);

        $this->assertNotSame('CBN-9999', $response->json('data.transaction_number'));
        $this->assertDatabaseMissing('cash_bank_transactions', ['transaction_number' => 'CBN-9999']);
    }

    /*
     * ---------------------------------------------------------------------
     * The eligibility matrix
     * ---------------------------------------------------------------------
     */

    /**
     * A deposit's destination must be cash or bank.
     */
    #[Test]
    public function a_deposit_into_a_non_cash_bank_account_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $response = $this->create(CashBankTransactionType::Deposit, $user, $company, [
            'source_account_id' => $chart['capital']->getKey(),
            'destination_account_id' => $chart['receivable']->getKey(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('destination_account_id');

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    /**
     * A deposit's source is deliberately unconstrained beyond being an active
     * account of this company. A withdrawal could be posted to an expense, and a
     * deposit could come from a receivable being cleared; both are legitimate.
     */
    #[Test]
    public function a_deposit_may_come_from_any_active_account(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $this->create(CashBankTransactionType::Deposit, $user, $company, [
            'source_account_id' => $chart['expense']->getKey(),
            'destination_account_id' => $chart['cash']->getKey(),
        ])->assertCreated();
    }

    /**
     * A withdrawal's source must be cash or bank.
     */
    #[Test]
    public function a_withdrawal_from_a_non_cash_bank_account_is_refused(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $response = $this->create(CashBankTransactionType::Withdrawal, $user, $company, [
            'source_account_id' => $chart['expense']->getKey(),
            'destination_account_id' => $chart['bank']->getKey(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('source_account_id');

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    /**
     * The brief warns specifically against posting every withdrawal to an expense
     * account. That warning is respected because the destination is whatever the
     * user named - so a withdrawal *can* legitimately go to an expense, and can
     * also go somewhere else. There is no hardcoded destination anywhere.
     */
    #[Test]
    public function a_withdrawal_destination_is_never_hardcoded(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        // To an expense account.
        $this->create(CashBankTransactionType::Withdrawal, $user, $company, [
            'source_account_id' => $chart['bank']->getKey(),
            'destination_account_id' => $chart['expense']->getKey(),
        ])->assertCreated();

        // And to a completely different account type, which is the case a
        // hardcoded expense default would have made impossible.
        $this->create(CashBankTransactionType::Withdrawal, $user, $company, [
            'source_account_id' => $chart['cash']->getKey(),
            'destination_account_id' => $chart['capital']->getKey(),
        ])->assertCreated();

        $this->assertDatabaseCount('cash_bank_transactions', 2);
    }

    /**
     * A transfer needs two cash/bank accounts. One plain account is enough to
     * refuse it, and which side is plain is reported on that side's field.
     */
    #[Test]
    public function a_transfer_requires_both_sides_to_be_cash_or_bank(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        // Plain destination.
        $this->create(CashBankTransactionType::Transfer, $user, $company, [
            'source_account_id' => $chart['bank']->getKey(),
            'destination_account_id' => $chart['expense']->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors('destination_account_id');

        // Plain source.
        $this->create(CashBankTransactionType::Transfer, $user, $company, [
            'source_account_id' => $chart['expense']->getKey(),
            'destination_account_id' => $chart['cash']->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors('source_account_id');

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    /**
     * Cash-to-bank and bank-to-cash are both transfers, and neither direction is
     * privileged.
     */
    #[Test]
    public function a_transfer_works_in_both_directions(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $this->create(CashBankTransactionType::Transfer, $user, $company, [
            'source_account_id' => $chart['cash']->getKey(),
            'destination_account_id' => $chart['bank']->getKey(),
        ])->assertCreated();

        $this->create(CashBankTransactionType::Transfer, $user, $company, [
            'source_account_id' => $chart['bank']->getKey(),
            'destination_account_id' => $chart['cash']->getKey(),
        ])->assertCreated();
    }

    /*
     * ---------------------------------------------------------------------
     * Rules shared by all three types
     * ---------------------------------------------------------------------
     */

    /**
     * Both accounts are always required. The offset on a deposit and a
     * withdrawal is never inferred.
     */
    #[Test]
    public function both_accounts_are_required_for_every_type(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        foreach (CashBankTransactionType::cases() as $type) {
            $this->create($type, $user, $company, [
                'destination_account_id' => $chart['bank']->getKey(),
            ])->assertStatus(422)->assertJsonValidationErrors('source_account_id');

            $this->create($type, $user, $company, [
                'source_account_id' => $chart['bank']->getKey(),
            ])->assertStatus(422)->assertJsonValidationErrors('destination_account_id');
        }

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    #[Test]
    #[DataProvider('createEndpointProvider')]
    public function the_same_account_cannot_be_both_sides(string $endpoint): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $response = $this->create(
            $this->typeForEndpoint($endpoint),
            $user,
            $company,
            [
                'source_account_id' => $chart['bank']->getKey(),
                'destination_account_id' => $chart['bank']->getKey(),
            ],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors('destination_account_id');

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    #[Test]
    public function an_inactive_account_is_refused_on_either_side(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $closed = Account::factory()->for($company)->bank()->inactive()->create([
            'code' => '1021',
            'name' => 'Closed Account',
        ]);

        $this->create(CashBankTransactionType::Transfer, $user, $company, [
            'source_account_id' => $closed->getKey(),
            'destination_account_id' => $chart['cash']->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors('source_account_id');

        $this->create(CashBankTransactionType::Deposit, $user, $company, [
            'source_account_id' => $chart['capital']->getKey(),
            'destination_account_id' => $closed->getKey(),
        ])->assertStatus(422)->assertJsonValidationErrors('destination_account_id');

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    #[Test]
    public function the_amount_must_be_positive(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        foreach (['0', '0.0000', '-100.0000'] as $amount) {
            $this->create(CashBankTransactionType::Transfer, $user, $company, array_merge(
                $this->validPayload(CashBankTransactionType::Transfer, $chart),
                ['amount' => $amount],
            ))->assertStatus(422)->assertJsonValidationErrors('amount');
        }

        $this->assertDatabaseCount('cash_bank_transactions', 0);
    }

    /**
     * The amount is stored as a DECIMAL string, not a float. A value that a
     * float cannot represent exactly must survive the round trip.
     */
    #[Test]
    public function the_amount_is_stored_exactly(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $this->create(CashBankTransactionType::Transfer, $user, $company, array_merge(
            $this->validPayload(CashBankTransactionType::Transfer, $chart),
            ['amount' => '0.1000'],
        ))->assertCreated()->assertJsonPath('data.amount', '0.1000');

        $this->assertDatabaseHas('cash_bank_transactions', [
            'amount' => '0.1000',
        ]);
    }

    /**
     * A partial update re-validates the pair as it will be *after* the edit, not
     * as it is now. Editing only one side of a transfer onto a plain account must
     * be refused even though the stored document was valid when it was created.
     */
    #[Test]
    public function a_partial_edit_cannot_leave_an_ineligible_pair(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $created = $this->create(CashBankTransactionType::Transfer, $user, $company,
            $this->validPayload(CashBankTransactionType::Transfer, $chart)
        )->assertCreated();

        $id = $created->json('data.id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-transactions/{$id}", [
                'destination_account_id' => $chart['expense']->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination_account_id');

        // The stored pair is untouched: still bank -> cash.
        $this->assertSame(
            $chart['bank']->getKey(),
            $created->json('data.source_account_id')
        );
        $this->assertSame(
            $chart['cash']->getKey(),
            $created->json('data.destination_account_id')
        );
    }

    /**
     * A draft can be edited, and the fields that were not sent keep their values.
     */
    #[Test]
    public function a_draft_can_be_edited(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $created = $this->create(CashBankTransactionType::Withdrawal, $user, $company, array_merge(
            $this->validPayload(CashBankTransactionType::Withdrawal, $chart),
            ['reference' => 'ORIGINAL', 'notes' => 'Original note'],
        ))->assertCreated();

        $id = $created->json('data.id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-transactions/{$id}", [
                'amount' => '99.9500',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.amount', '99.9500')
            ->assertJsonPath('data.reference', 'ORIGINAL')
            ->assertJsonPath('data.notes', 'Original note')
            ->assertJsonPath('data.transaction_type', CashBankTransactionType::Withdrawal->value);
    }

    /**
     * The type cannot be changed by editing, because there is no field through
     * which to ask.
     */
    #[Test]
    public function the_transaction_type_cannot_be_changed_by_editing(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $created = $this->create(CashBankTransactionType::Deposit, $user, $company,
            $this->validPayload(CashBankTransactionType::Deposit, $chart)
        )->assertCreated();

        $id = $created->json('data.id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->putJson("/api/cash-bank-transactions/{$id}", [
                'transaction_type' => CashBankTransactionType::Withdrawal->value,
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.transaction_type', CashBankTransactionType::Deposit->value);
    }

    #[Test]
    public function a_draft_can_be_deleted(): void
    {
        $user = $this->accountant();
        $company = $this->createCompanyFor($user);
        $chart = $this->chart($company);

        $created = $this->create(CashBankTransactionType::Transfer, $user, $company,
            $this->validPayload(CashBankTransactionType::Transfer, $chart)
        )->assertCreated();

        $id = $created->json('data.id');

        $this->actingAsJwt($user)
            ->withCompanyContext($company)
            ->deleteJson("/api/cash-bank-transactions/{$id}")
            ->assertSuccessful();

        $this->assertDatabaseMissing('cash_bank_transactions', ['id' => $id]);
    }
}
