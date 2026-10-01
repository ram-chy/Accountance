<?php

namespace App\Enums;

/**
 * The three kinds of movement a cash/bank transaction represents.
 *
 * The direction is deliberately not part of the entry that produces the journal,
 * because the direction is already implied by the pair of accounts: money enters
 * the destination and leaves the source in all three cases. What differs is which
 * of the two accounts has to be an eligible cash/bank account, and that is a
 * rule about the transaction rather than about the money.
 *
 * DEPOSIT and WITHDRAWAL are the two-sided form, where exactly one side is a
 * cash/bank account and the other is an explicit account the caller chooses - an
 * expense for a bank charge, an equity account for capital introduced. Neither
 * is hard-coded, because "money left the bank" is not by itself an accounting
 * instruction.
 *
 * TRANSFER is the only case where both sides must be cash/bank accounts, which
 * is what makes it an internal movement: nothing about the company's position
 * changes, only where the money sits.
 *
 * A client cannot set this. It comes from which endpoint was called, because a
 * deposit that is silently reclassified as a transfer would post to a different
 * pair of accounts than the user asked for.
 */
enum CashBankTransactionType: string
{
    case Deposit = 'DEPOSIT';
    case Withdrawal = 'WITHDRAWAL';
    case Transfer = 'TRANSFER';

    /**
     * Does this movement require both accounts to be cash/bank accounts?
     *
     * True only for a transfer. For a deposit the destination must be cash/bank
     * and the source is an arbitrary account; for a withdrawal it is the other
     * way round.
     */
    public function requiresBothAccountsCashBank(): bool
    {
        return $this === self::Transfer;
    }

    /**
     * Which side of the transaction must be a cash/bank account.
     *
     * The entry is always Dr destination / Cr source, so for a deposit the
     * destination is the cash/bank account and for a withdrawal it is the
     * source. A transfer defers to the both-accounts rule above instead, and
     * returning null makes that explicit rather than having this method quietly
     * pick one side.
     */
    public function cashBankSide(): ?string
    {
        return match ($this) {
            self::Deposit => 'destination_account_id',
            self::Withdrawal => 'source_account_id',
            self::Transfer => null,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
