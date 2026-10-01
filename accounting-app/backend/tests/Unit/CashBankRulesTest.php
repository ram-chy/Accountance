<?php

namespace Tests\Unit;

use App\Enums\CashBankKind;
use App\Enums\CashBankTransactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rules the movement type encodes, as pure logic.
 *
 * This is the one place in Phase 7 where the decision can be checked without a
 * database, and it is worth separating because these two methods are what the
 * draft service and the posting service both consult. If they ever disagreed with
 * each other, a transaction could be created under one rule and posted under
 * another - and since both are currently the same enum, that cannot happen by
 * construction. These tests exist to make sure a future edit does not introduce a
 * fourth case or quietly widen one of them.
 *
 * The matrix in the feature tests is the behavioural version of this file; this
 * one is the exhaustive statement of the rule with no fixtures in the way.
 */
class CashBankRulesTest extends TestCase
{
    #[Test]
    public function there_are_exactly_three_movement_types(): void
    {
        $this->assertSame(
            ['DEPOSIT', 'WITHDRAWAL', 'TRANSFER'],
            CashBankTransactionType::values()
        );
    }

    #[Test]
    public function there_are_exactly_two_cash_bank_kinds(): void
    {
        $this->assertSame(['CASH', 'BANK'], CashBankKind::values());
    }

    /**
     * A transfer is the only type that requires both sides to be cash/bank.
     *
     * Asserted exhaustively over all three cases rather than for Transfer alone,
     * so that adding a fourth movement type would fail here rather than silently
     * inheriting the transfer behaviour.
     */
    #[Test]
    #[DataProvider('bothAccountsProvider')]
    public function only_a_transfer_requires_both_accounts_to_be_cash_bank(
        CashBankTransactionType $type,
        bool $expected
    ): void {
        $this->assertSame($expected, $type->requiresBothAccountsCashBank());
    }

    /**
     * @return array<string, array{0: CashBankTransactionType, 1: bool}>
     */
    public static function bothAccountsProvider(): array
    {
        return [
            'deposit' => [CashBankTransactionType::Deposit, false],
            'withdrawal' => [CashBankTransactionType::Withdrawal, false],
            'transfer' => [CashBankTransactionType::Transfer, true],
        ];
    }

    /**
     * Which side has to be cash/bank: the destination for a deposit, the source
     * for a withdrawal, and nothing for a transfer.
     *
     * A transfer returns null rather than picking a side, so the caller has to
     * handle it explicitly - which is what stops a transfer being validated by
     * the two-sided path.
     */
    #[Test]
    #[DataProvider('cashBankSideProvider')]
    public function the_cash_bank_side_follows_the_direction_of_the_movement(
        CashBankTransactionType $type,
        ?string $expected
    ): void {
        $this->assertSame($expected, $type->cashBankSide());
    }

    /**
     * @return array<string, array{0: CashBankTransactionType, 1: string|null}>
     */
    public static function cashBankSideProvider(): array
    {
        return [
            'a deposit lands in the destination' => [
                CashBankTransactionType::Deposit,
                'destination_account_id',
            ],
            'a withdrawal leaves the source' => [
                CashBankTransactionType::Withdrawal,
                'source_account_id',
            ],
            'a transfer defers to the both-accounts rule' => [
                CashBankTransactionType::Transfer,
                null,
            ],
        ];
    }

    /**
     * The two methods must be consistent with each other.
     *
     * These are the properties the services actually branch on, and they are
     * checked here as a pair because a type with a side *and* a both-accounts rule
     * would be ambiguous - the posting service checks the both-accounts rule first,
     * so the side would be silently ignored, and that is the kind of thing a
     * well-meaning edit introduces without noticing.
     */
    #[Test]
    public function no_type_is_both_a_sided_and_a_two_sided_movement(): void
    {
        foreach (CashBankTransactionType::cases() as $type) {
            $this->assertNotSame(
                $type->requiresBothAccountsCashBank(),
                $type->cashBankSide() !== null,
                "{$type->value} declares both a cash/bank side and a both-accounts rule."
            );
        }
    }

    /**
     * The side a type names must be one the schema actually has. A typo here would
     * pass every unit test here and fail at runtime with an account id that does
     * not resolve.
     */
    #[Test]
    public function the_named_side_is_a_real_column_on_the_transaction(): void
    {
        foreach (CashBankTransactionType::cases() as $type) {
            $side = $type->cashBankSide();

            if ($side === null) {
                continue;
            }

            $this->assertContains(
                $side,
                ['source_account_id', 'destination_account_id'],
                "{$type->value} names [{$side}], which is not an account column."
            );
        }
    }
}
