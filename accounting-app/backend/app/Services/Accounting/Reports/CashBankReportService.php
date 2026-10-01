<?php

namespace App\Services\Accounting\Reports;

use App\Models\Account;
use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Cash and bank activity for one account.
 *
 * This is the general-ledger shape deliberately: opening balance, dated
 * movements with a running balance, period totals and a closing balance. A
 * dedicated implementation would restate the same query and the same running
 * arithmetic, and the two would drift. The only thing this class adds is the
 * name and the intent - it is meant for the cash/bank accounts a receipt or
 * payment settles into.
 *
 * It is not type-restricted. Nothing in Phase 5 requires a payment account to be
 * an ASSET in the schema, so refusing a liability-typed account here would
 * invent a rule; the report returns whichever account it is given.
 */
class CashBankReportService
{
    public function __construct(private readonly GeneralLedgerReportService $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->ledger->generate($company, $account, $from, $to);
    }
}
