<?php

namespace Tests\Unit;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Services\Accounting\AccountingRules;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The central accounting rules: normal balance and sign arithmetic.
 *
 * These are pure functions of account type and money, so they are tested
 * directly rather than through the API. A failure here means every report in
 * the system is wrong, which is why the contra cases are covered explicitly
 * rather than left to the balance tests.
 */
class AccountingRulesTest extends TestCase
{
    use RefreshDatabase;

    private AccountingRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = app(AccountingRules::class);
    }

    /**
     * The mapping the spec states outright. If this changes, every balance in the
     * application changes with it.
     */
    #[Test]
    public function each_account_type_has_the_normal_balance_the_spec_requires(): void
    {
        $this->assertSame(NormalBalance::Debit, AccountType::Asset->normalBalance());
        $this->assertSame(NormalBalance::Debit, AccountType::Expense->normalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Liability->normalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Equity->normalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Revenue->normalBalance());
    }

    #[Test]
    public function there_are_exactly_five_account_types(): void
    {
        // A growing list of account types is how "miscellaneous" ends up meaning
        // different things to different reports.
        $this->assertCount(5, AccountType::cases());
    }

    #[Test]
    public function an_account_without_an_override_uses_its_types_normal_balance(): void
    {
        $account = Account::factory()->asset()->create(['normal_balance' => null]);

        $this->assertSame(NormalBalance::Debit, $this->rules->normalBalanceFor($account));
        $this->assertFalse($account->isContra());
    }

    #[Test]
    public function an_explicit_override_wins_over_the_account_type(): void
    {
        // An asset that is credit-normal: accumulated depreciation.
        $account = Account::factory()->asset()->create([
            'normal_balance' => NormalBalance::Credit->value,
        ]);

        $this->assertSame(NormalBalance::Credit, $this->rules->normalBalanceFor($account));
        $this->assertTrue($account->isContra());
    }

    #[Test]
    public function an_override_matching_the_type_is_not_reported_as_contra(): void
    {
        // Explicitly restating the natural side is redundant, not contra. Saying
        // so keeps the flag meaningful as a "this account is unusual" signal.
        $account = Account::factory()->asset()->create([
            'normal_balance' => NormalBalance::Debit->value,
        ]);

        $this->assertFalse($account->isContra());
    }

    /**
     * A debit-normal account: more debits than credits is a positive balance.
     */
    #[Test]
    public function a_debit_normal_balance_is_debits_less_credits(): void
    {
        $balance = $this->rules->signedBalance(
            Money::of('1000.00'),
            Money::of('250.00'),
            NormalBalance::Debit,
        );

        $this->assertSame('750.0000', (string) $balance);
        $this->assertTrue($balance->isPositive());
    }

    /**
     * The same figures on a credit-normal account invert the sign. This is the
     * case the spec warns about: hard-coding debit - credit gets revenue wrong.
     */
    #[Test]
    public function a_credit_normal_balance_is_the_inverse(): void
    {
        $balance = $this->rules->signedBalance(
            Money::of('1000.00'),
            Money::of('250.00'),
            NormalBalance::Credit,
        );

        $this->assertSame('-750.0000', (string) $balance);
        $this->assertTrue($balance->isNegative());
    }

    #[Test]
    public function an_over_balanced_account_reports_a_negative_balance(): void
    {
        // An asset with more credits than debits. The honest answer is a
        // negative balance, not the magnitude with the sign hidden.
        $balance = $this->rules->signedBalance(
            Money::of('100.00'),
            Money::of('400.00'),
            NormalBalance::Debit,
        );

        $this->assertSame('-300.0000', (string) $balance);
    }

    #[Test]
    public function net_movement_is_direction_independent(): void
    {
        $net = $this->rules->netMovement(Money::of('900.00'), Money::of('100.00'));

        $this->assertSame('800.0000', (string) $net);
    }

    #[Test]
    #[DataProvider('trialBalanceSplits')]
    public function trial_balance_placement_follows_the_normal_balance(
        string $debit,
        string $credit,
        NormalBalance $normal,
        string $expectedDebit,
        string $expectedCredit,
    ): void {
        $signed = $this->rules->signedBalance(Money::of($debit), Money::of($credit), $normal);

        $split = $this->rules->splitForTrialBalance($signed, $normal);

        $this->assertSame($expectedDebit, (string) $split['debit']);
        $this->assertSame($expectedCredit, (string) $split['credit']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: NormalBalance, 3: string, 4: string}>
     */
    public static function trialBalanceSplits(): array
    {
        return [
            'debit-normal positive goes in the debit column' => [
                '1000.00', '0.00', NormalBalance::Debit, '1000.0000', '0.0000',
            ],
            'debit-normal negative goes in the credit column' => [
                '0.00', '1000.00', NormalBalance::Debit, '0.0000', '1000.0000',
            ],
            'credit-normal positive goes in the credit column' => [
                '0.00', '1000.00', NormalBalance::Credit, '0.0000', '1000.0000',
            ],
            'credit-normal negative goes in the debit column' => [
                '1000.00', '0.00', NormalBalance::Credit, '1000.0000', '0.0000',
            ],
            'a zero balance occupies neither column' => [
                '500.00', '500.00', NormalBalance::Debit, '0.0000', '0.0000',
            ],
        ];
    }

    /**
     * The trial-balance footing property: whatever the figures, splitting them
     * across two columns preserves the total. This is what makes a trial balance
     * foot, and it only holds because magnitude() is applied to the sign side.
     */
    #[Test]
    public function splitting_preserves_the_magnitude(): void
    {
        $debits = Money::of('0.00');

        foreach ([['1000.00', '0.00', NormalBalance::Debit], ['300.00', '0.00', NormalBalance::Debit]] as [$d, $c, $normal]) {
            $signed = $this->rules->signedBalance(Money::of($d), Money::of($c), $normal);
            $split = $this->rules->splitForTrialBalance($signed, $normal);

            $debits = $debits->plus($split['debit'])->plus($split['credit']);
        }

        $this->assertSame('1300.0000', (string) $debits);
    }
}
