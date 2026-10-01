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
 * It is still not type-restricted, and that remains correct: this report answers
 * "show me the ledger for this account", and the Phase 6 report response contract
 * is not changed here to make it narrower.
 *
 * Corrected in Phase 7. This docblock previously claimed that "nothing in Phase 5
 * requires a payment account to be an ASSET in the schema". That was not true:
 * TransactionAccountResolver::payment() accepts only AccountType::Asset, and both
 * the customer_receipts and supplier_payments migrations state that
 * payment_account_id must be an ASSET account. The *report* is unrestricted; the
 * *transaction* path that feeds it is not. The distinction matters now that
 * accounts carry an explicit cash_bank_kind, because a reader relying on the old
 * wording would conclude that any account could be settled into, and would be
 * wrong.
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
