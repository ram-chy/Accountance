<?php

namespace App\Enums;

/**
 * The five fundamental account types.
 *
 * These are deliberately exhaustive and few. Accounting systems that grow a long
 * list of "types" usually end up with types that mean different things to
 * different reports, and there is no accounting authority that recognises most
 * of them. Anything finer-grained than these five belongs in a report or a
 * document, not in the chart of accounts.
 */
enum AccountType: string
{
    case Asset = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity = 'EQUITY';
    case Revenue = 'REVENUE';
    case Expense = 'EXPENSE';

    /**
     * The side this type normally carries a balance on.
     *
     * This mapping is the single source of truth for normal-balance behaviour.
     * Report code and the ledger must ask here rather than restating the rule,
     * which is how "revenue is credit-normal" ends up inverted in three
     * different reports.
     */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }

    /**
     * True when an increase in this account type is recorded as a credit.
     */
    public function increasesOnCredit(): bool
    {
        return $this->normalBalance() === NormalBalance::Credit;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
