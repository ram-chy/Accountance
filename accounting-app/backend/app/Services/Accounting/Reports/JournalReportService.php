<?php

namespace App\Services\Accounting\Reports;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Services\Accounting\LedgerService;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared plumbing for the reports that read posted journal lines.
 *
 * Two report families exist in Phase 6 and they have different sources of
 * truth. The financial statements (trial balance, general ledger, P&L, balance
 * sheet) read the ledger: `journal_lines` joined to `journals` with
 * `status = 'POSTED'`. The settlement reports (receivables, payables, aging,
 * statements) read Phase 5 documents and allocations. Only the first family
 * belongs here; mixing them would hide the fact that they answer different
 * questions from different tables.
 *
 * This class deliberately does not define the posted-only query or the
 * per-account aggregate itself. LedgerService already owns both, and every
 * balance in the system - Phase 4's own reports included - must come from that
 * one definition. A second copy here would eventually disagree with it.
 */
abstract class JournalReportService
{
    public function __construct(protected readonly LedgerService $ledger) {}

    /**
     * The posted-only, company-scoped base query.
     */
    protected function postedLines(Company $company): Builder
    {
        return $this->ledger->postedLinesQuery($company->getKey());
    }

    /**
     * Inclusive date bounds on a journal column.
     *
     * `journal_date` is a DATE column, so the comparison is date-to-date: a
     * journal dated the `from` day is included, and one dated the `to` day is
     * included. There is no time component to reason about and no created_at /
     * posted_at is ever consulted, because an entry belongs to the period it is
     * dated, not to the moment it was typed.
     */
    protected function withinPeriod(Builder $query, ?Carbon $from, ?Carbon $to, string $column = 'journals.journal_date'): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->where($column, '>=', $from->toDateString()))
            ->when($to, fn (Builder $q) => $q->where($column, '<=', $to->toDateString()));
    }

    /**
     * Gross debit and credit per account, from posted lines only.
     *
     * @return Collection<int, array{debit: Money, credit: Money}>
     */
    protected function totalsByAccount(Company $company, ?Carbon $from, ?Carbon $to): Collection
    {
        return $this->ledger->postedTotalsByAccount($company, $from, $to);
    }

    /**
     * A raw debit-positive net movement, `debit - credit`.
     *
     * This is the direction-independent figure. It is what the database sums
     * and what a general ledger prints before deciding which column a balance
     * belongs in.
     */
    protected function netDebit(Money $debit, Money $credit): Money
    {
        return $debit->minus($credit);
    }

    /**
     * A balance expressed on its account type's normal side.
     *
     * The account type, not the per-account contra override, decides the sign.
     * That distinction matters for report *sections*: a contra asset (accumulated
     * depreciation) carries a credit balance and must reduce the asset total, so
     * it is signed on the asset type's debit side and comes out negative. Signing
     * by the override instead would turn the same account positive and inflate
     * the section it is supposed to reduce.
     *
     * @param  AccountType  $type  the account's *type*, never the effective normal balance
     */
    protected function signedForType(Money $debit, Money $credit, AccountType $type): Money
    {
        return $type->increasesOnCredit() ? $credit->minus($debit) : $debit->minus($credit);
    }

    /**
     * The reporting window, echoed back so a client can prove what it asked for.
     *
     * @return array{from: string|null, to: string|null}
     */
    protected function period(?Carbon $from, ?Carbon $to): array
    {
        return [
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ];
    }

    /**
     * A currency-less monetary string in the system's canonical 4-decimal form.
     */
    protected function amount(Money $money): string
    {
        return $money->toDatabase();
    }

    /**
     * Accounts of one type, each with its balance signed on that type's side.
     *
     * The type is passed in rather than read from the account here, because the
     * caller decides which section the rows belong to and the two must not
     * disagree. Rows are ordered by code so a statement is stable between calls,
     * and zero-balance accounts are dropped unless asked for.
     *
     * @param  Collection<int, array{debit: Money, credit: Money}>  $totals
     * @return array{rows: array<int, array<string, mixed>>, total: Money}
     */
    protected function signedRowsForType(
        Company $company,
        Collection $totals,
        AccountType $type,
        bool $includeZero = false
    ): array {
        $accounts = Account::query()
            ->where('company_id', $company->getKey())
            ->where('account_type', $type->value)
            ->whereIn('id', $totals->keys())
            ->orderBy('code')
            ->get();

        $rows = [];
        $total = Money::zero();

        foreach ($accounts as $account) {
            $bucket = $totals->get($account->getKey());
            $signed = $this->signedForType($bucket['debit'], $bucket['credit'], $type);

            if (! $includeZero && $signed->isZero()) {
                continue;
            }

            $total = $total->plus($signed);

            $rows[] = [
                'account_id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $this->amount($signed),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Cumulative net profit through a date: revenue less expenses, all time.
     *
     * This is the balance sheet's retained earnings. It is derived from the same
     * posted lines as everything else and is never stored, so it cannot drift
     * from the P&L. Expenses are signed on their debit side and revenue on its
     * credit side, so the subtraction is `revenue - expenses` in ordinary terms.
     */
    protected function retainedEarnings(Company $company, Carbon $through): Money
    {
        $totals = $this->totalsByAccount($company, null, $through);

        $revenue = $this->signedRowsForType($company, $totals, AccountType::Revenue)['total'];
        $expenses = $this->signedRowsForType($company, $totals, AccountType::Expense)['total'];

        return $revenue->minus($expenses);
    }
}
