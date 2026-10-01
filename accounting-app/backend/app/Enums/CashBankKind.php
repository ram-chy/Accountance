<?php

namespace App\Enums;

/**
 * Whether an account holds physical cash or represents a bank account.
 *
 * Phase 7 needed this and the schema had nothing to hold it, so it is a new
 * classification rather than a reuse of something existing. It was rejected as a
 * new AccountType for two reasons: AccountType is deliberately exhaustive and
 * five cases wide ("anything finer-grained than these five belongs in a report
 * or a document, not in the chart of accounts"), and a sixth type would make
 * every existing balance-sheet and trial-balance query - all of which filter on
 * account_type - responsible for excluding cash and bank. Cash and bank are
 * assets first and cash or bank second; the account type carries the first fact,
 * and this column carries the second.
 *
 * Null means "not a cash or bank account", which is the ordinary case. It is not
 * a third state to be interpreted: an account without a kind simply is not
 * eligible for a cash/bank transaction.
 */
enum CashBankKind: string
{
    case Cash = 'CASH';
    case Bank = 'BANK';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
