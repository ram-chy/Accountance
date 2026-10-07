<?php

namespace App\Services\Accounting\Reports;

use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * General ledger: every posted movement on one account, with a running balance.
 *
 * The opening / running / closing figures are all raw debit-positive, following
 * the definitions in the brief:
 *
 *   opening  = SUM(debits before from_date) - SUM(credits before from_date)
 *   running  = opening + SUM(debits to row)  - SUM(credits to row)
 *   closing  = opening + period_debits       - period_credits
 *
 * That is deliberately not the normal-balance-signed figure LedgerService's
 * account statement returns. A general ledger is a movement listing where a
 * debit always adds and a credit always subtracts, whatever the account's type;
 * signing by normal balance would make every liability account's running balance
 * run backwards. The account's type and effective normal balance are included in
 * the response so a reader can interpret the raw figure, and the signed closing
 * balance is provided alongside it for convenience.
 *
 * Ordering is deterministic and never relies on physical row order:
 * journal_date, then journal id, then journal line id. Without the id tiebreaks
 * two entries on the same day would come back in whatever order the engine
 * chose, and a running balance over them would be non-reproducible.
 */
class GeneralLedgerReportService extends JournalReportService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(Company $company, Account $account, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $opening = $this->openingBalance($company, $account, $from);

        $rows = $this->movements($company, $account, $from, $to);

        $periodDebit = Money::zero();
        $periodCredit = Money::zero();
        $running = $opening;

        $lines = [];

        foreach ($rows as $row) {
            $debit = Money::of((string) $row->debit);
            $credit = Money::of((string) $row->credit);

            $periodDebit = $periodDebit->plus($debit);
            $periodCredit = $periodCredit->plus($credit);

            /*
             * Recomputed from the cumulative movement rather than incremented by
             * a delta. A delta would have to add or subtract depending on the
             * running balance's sign, which is the classic way a ledger gets a
             * running balance wrong the moment it crosses zero.
             */
            $running = $running->plus($debit)->minus($credit);

            $sourceType = $row->source_type ?? 'MANUAL';

            $isForeign = $row->currency_id !== null;
            $foreignDebit = $row->foreign_debit;
            $foreignCredit = $row->foreign_credit;

            $line = [
                'journal_id' => (int) $row->journal_id,
                'journal_line_id' => (int) $row->journal_line_id,
                'journal_number' => $row->journal_number,
                'journal_date' => Carbon::parse($row->journal_date)->toDateString(),
                'reference' => $row->reference,
                'description' => $row->line_description ?? $row->journal_description,
                'source_type' => $sourceType,
                'source_id' => $row->source_id === null ? null : (int) $row->source_id,
                'debit' => $this->amount($debit),
                'credit' => $this->amount($credit),
                'running_balance' => $this->amount($running),
            ];

            /*
             * FX provenance. Present for every row so the shape is stable, null
             * for a base-currency line: a client that has to guess whether a
             * field exists will guess wrong on the first foreign row it sees.
             */
            $line['currency_id'] = $isForeign ? (int) $row->currency_id : null;
            $line['exchange_rate'] = $row->exchange_rate;
            $line['foreign_debit'] = $isForeign && $foreignDebit !== null
                ? Money::of((string) $foreignDebit)->toDatabase()
                : null;
            $line['foreign_credit'] = $isForeign && $foreignCredit !== null
                ? Money::of((string) $foreignCredit)->toDatabase()
                : null;

            $lines[] = $line;
        }

        $closing = $opening->plus($periodDebit)->minus($periodCredit);
        $normalBalance = $account->normalBalance();

        return [
            'account' => [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $normalBalance->value,
                'is_active' => $account->is_active,
            ],
            'period' => $this->period($from, $to),
            /*
             * Base-currency disclosure (§21.1). Every balance above is in this
             * currency; the foreign_* columns on each row are provenance only and
             * never feed a total.
             */
            'base_currency' => $this->baseCurrency($company),
            'opening_balance' => $this->amount($opening),
            'rows' => $lines,
            'period_debits' => $this->amount($periodDebit),
            'period_credits' => $this->amount($periodCredit),
            'closing_balance' => $this->amount($closing),
            /*
             * The same closing figure on the account's normal side. An asset
             * ledger reads the same either way; a liability ledger is negative
             * in the raw column and positive here, which is why both are
             * returned rather than choosing for the client.
             */
            'closing_balance_signed' => $this->amount(
                $normalBalance === NormalBalance::Credit ? $closing->negate() : $closing
            ),
        ];
    }

    /**
     * Raw debit-positive movement before the window opens.
     */
    private function openingBalance(Company $company, Account $account, ?Carbon $from): Money
    {
        if ($from === null) {
            return Money::zero();
        }

        $row = $this->postedLines($company)
            ->where('journal_lines.account_id', $account->getKey())
            ->where('journals.journal_date', '<', $from->toDateString())
            ->selectRaw('coalesce(sum(journal_lines.debit), 0) as total_debit, '
                .'coalesce(sum(journal_lines.credit), 0) as total_credit')
            ->first();

        return $this->netDebit(Money::of((string) $row->total_debit), Money::of((string) $row->total_credit));
    }

    /**
     * The window's movements in deterministic order.
     *
     * @return Collection<int, object>
     */
    private function movements(Company $company, Account $account, ?Carbon $from, ?Carbon $to): Collection
    {
        return $this->withinPeriod(
            $this->postedLines($company)->where('journal_lines.account_id', $account->getKey()),
            $from,
            $to
        )
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->orderBy('journal_lines.id')
            ->select([
                'journals.id as journal_id',
                'journals.journal_number',
                'journals.journal_date',
                'journals.description as journal_description',
                'journals.reference',
                'journals.source_type',
                'journals.source_id',
                'journal_lines.id as journal_line_id',
                'journal_lines.description as line_description',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.currency_id',
                'journal_lines.foreign_debit',
                'journal_lines.foreign_credit',
                'journal_lines.exchange_rate',
            ])
            ->get();
    }
}
