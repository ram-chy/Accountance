<?php

namespace App\Services\Accounting\Controls;

use App\Enums\ControlStatus;
use App\Enums\JournalStatus;
use App\Models\Company;
use App\Models\JournalLine;
use App\Services\Accounting\Currency\CompanyCurrencyService;
use App\Services\Accounting\Currency\RealizedFxService;
use Illuminate\Support\Collection;

/**
 * The accounting controls that concern currency and foreign exchange.
 *
 * READ-ONLY BY CONSTRUCTION. Every method returns findings; none of them writes,
 * corrects or migrates anything. That is the rule §24 sets - the control layer
 * detects problems, it does not repair financial data - and it is enforced here by
 * the class having no write path at all rather than by a comment asking callers to
 * behave.
 *
 * WHY A LIST RATHER THAN A BOOLEAN
 *
 * ControlStatus has three states because the questions these checks ask are not all
 * yes/no. "Is the base currency active?" is a fault when the answer is no. "Can the
 * base currency still be changed?" is not a fault at all - it is a property of a
 * company that has already posted history, and the correct response to seeing it is
 * to understand why a change will be refused, not to fix anything. A boolean report
 * would have to call one of those broken or hide it, so a finding carries its own
 * status instead.
 *
 * WHY IT REUSES THE WRITE SERVICES
 *
 * baseCurrencyChangeSafety and foreignSettlement read their facts from
 * CompanyCurrencyService and RealizedFxService rather than re-deriving them. A
 * control that disagreed with the rule it is meant to monitor would be worse than no
 * control at all, so the rule has one implementation and this class asks it.
 */
class AccountingControlService
{
    public const BASE_CURRENCY = 'COMPANY_BASE_CURRENCY';

    public const BASE_CURRENCY_CHANGE_SAFETY = 'BASE_CURRENCY_CHANGE_SAFETY';

    public const FOREIGN_TRANSACTION_RATE = 'FOREIGN_TRANSACTION_RATE';

    public const INVALID_HISTORICAL_FX_STATE = 'INVALID_HISTORICAL_FX_STATE';

    public const FOREIGN_SETTLEMENT_ACCOUNTS = 'FOREIGN_SETTLEMENT_ACCOUNTS';

    public const ACCOUNT_DOCUMENT_CURRENCY = 'ACCOUNT_DOCUMENT_CURRENCY';

    public function __construct(
        private readonly CompanyCurrencyService $companyCurrency,
        private readonly RealizedFxService $realizedFx,
    ) {}

    /**
     * Run every control for a company.
     *
     * @return list<ControlFinding>
     */
    public function run(Company $company): array
    {
        return [
            ...$this->baseCurrencyFindings($company),
            ...$this->baseCurrencyChangeSafetyFindings($company),
            ...$this->foreignTransactionRateFindings($company),
            ...$this->invalidHistoricalFxFindings($company),
            ...$this->foreignSettlementFindings($company),
            ...$this->accountDocumentCurrencyFindings($company),
        ];
    }

    /**
     * Is the company's functional currency present, existing and usable?
     *
     * @return list<ControlFinding>
     */
    public function baseCurrencyFindings(Company $company): array
    {
        if ($company->currency_id === null) {
            return [$this->finding(
                self::BASE_CURRENCY,
                ControlStatus::Warning,
                'The company has no base currency configured. It books at an implicit rate of 1 and cannot transact in a foreign currency.',
                $company,
            )];
        }

        $currency = $company->currency;

        if ($currency === null) {
            return [$this->finding(
                self::BASE_CURRENCY,
                ControlStatus::Fail,
                sprintf(
                    'The company base currency (id %d) no longer exists. Its ledger cannot be interpreted.',
                    $company->currency_id,
                ),
                $company,
            )];
        }

        if (! $currency->is_active) {
            return [$this->finding(
                self::BASE_CURRENCY,
                ControlStatus::Warning,
                sprintf(
                    'The company base currency [%s] is inactive. Existing balances still resolve, but the currency is hidden from new selection.',
                    $currency->code,
                ),
                $company,
                ['currency_code' => $currency->code],
            )];
        }

        return [$this->finding(
            self::BASE_CURRENCY,
            ControlStatus::Pass,
            sprintf('The company base currency is [%s].', $currency->code),
            $company,
            ['currency_code' => $currency->code],
        )];
    }

    /**
     * Could the company's base currency still be changed?
     *
     * A change is never repaired here; this reports whether the history that makes
     * a change unsafe is present, so an operator who is about to be refused knows
     * why before they try.
     *
     * @return list<ControlFinding>
     */
    public function baseCurrencyChangeSafetyFindings(Company $company): array
    {
        if ($this->companyCurrency->hasPostedForeignLines($company)) {
            return [$this->finding(
                self::BASE_CURRENCY_CHANGE_SAFETY,
                ControlStatus::Warning,
                'The base currency cannot be changed: the company has posted foreign-currency entries whose stored base amounts are priced against the current base currency.',
                $company,
            )];
        }

        if ($this->companyCurrency->hasPostedAccounting($company)) {
            return [$this->finding(
                self::BASE_CURRENCY_CHANGE_SAFETY,
                ControlStatus::Warning,
                'The base currency cannot be changed while posted accounting data exists: a change would silently reinterpret every posted base amount.',
                $company,
            )];
        }

        return [$this->finding(
            self::BASE_CURRENCY_CHANGE_SAFETY,
            ControlStatus::Pass,
            'The base currency can still be changed safely; no posted accounting data has been booked against it yet.',
            $company,
        )];
    }

    /**
     * Foreign journal lines that carry no usable rate (or no base at all).
     *
     * The database CHECK already makes these shapes impossible through the
     * application; this control is the independent read that would notice a row
     * written around it.
     *
     * @return list<ControlFinding>
     */
    public function foreignTransactionRateFindings(Company $company): array
    {
        $findings = [];

        foreach ($this->postedForeignLines($company) as $line) {
            if ($company->currency_id === null) {
                $findings[] = $this->lineFinding(
                    self::FOREIGN_TRANSACTION_RATE,
                    ControlStatus::Fail,
                    'A foreign-currency journal line exists but the company has no base currency to convert it into.',
                    $line,
                );

                continue;
            }

            if ($line->exchange_rate === null || bccomp((string) $line->exchange_rate, '0', 10) <= 0) {
                $findings[] = $this->lineFinding(
                    self::FOREIGN_TRANSACTION_RATE,
                    ControlStatus::Fail,
                    'A foreign-currency journal line carries a foreign amount but no positive exchange rate.',
                    $line,
                );

                continue;
            }

            if ($line->foreign_debit === null && $line->foreign_credit === null) {
                $findings[] = $this->lineFinding(
                    self::FOREIGN_TRANSACTION_RATE,
                    ControlStatus::Fail,
                    'A journal line references a transaction currency but carries no foreign amount.',
                    $line,
                );
            }
        }

        return $findings === []
            ? [$this->passing(self::FOREIGN_TRANSACTION_RATE, 'Every foreign-currency line carries a positive rate and a foreign amount.')]
            : $findings;
    }

    /**
     * Posted lines whose foreign metadata contradicts itself or the base currency:
     * a base line carrying foreign columns, a rate that does not reconstruct the
     * base amount, or a line "in" the company's own base currency.
     *
     * @return list<ControlFinding>
     */
    public function invalidHistoricalFxFindings(Company $company): array
    {
        $findings = [];

        foreach ($this->postedLinesWithFxMetadata($company) as $line) {
            if ($line->currency_id === null) {
                if ($line->exchange_rate !== null || $line->foreign_debit !== null || $line->foreign_credit !== null) {
                    $findings[] = $this->lineFinding(
                        self::INVALID_HISTORICAL_FX_STATE,
                        ControlStatus::Fail,
                        'A base-currency journal line carries foreign-currency metadata; a base line must be entirely null in its foreign columns.',
                        $line,
                    );
                }

                continue;
            }

            if ($company->currency_id !== null && (int) $line->currency_id === (int) $company->currency_id) {
                $findings[] = $this->lineFinding(
                    self::INVALID_HISTORICAL_FX_STATE,
                    ControlStatus::Fail,
                    'A journal line is transacted in the company base currency but is stored as foreign; base-currency lines must store null currency metadata.',
                    $line,
                );

                continue;
            }

            if ($line->foreign_debit !== null && $line->exchange_rate !== null) {
                $expected = bcmul((string) $line->foreign_debit, (string) $line->exchange_rate, 4);

                if (bccomp($expected, (string) $line->debit, 4) !== 0) {
                    $findings[] = $this->lineFinding(
                        self::INVALID_HISTORICAL_FX_STATE,
                        ControlStatus::Fail,
                        'A foreign journal line\'s base debit does not equal its foreign amount times its stored exchange rate.',
                        $line,
                    );
                }
            }

            if ($line->foreign_credit !== null && $line->exchange_rate !== null) {
                $expected = bcmul((string) $line->foreign_credit, (string) $line->exchange_rate, 4);

                if (bccomp($expected, (string) $line->credit, 4) !== 0) {
                    $findings[] = $this->lineFinding(
                        self::INVALID_HISTORICAL_FX_STATE,
                        ControlStatus::Fail,
                        'A foreign journal line\'s base credit does not equal its foreign amount times its stored exchange rate.',
                        $line,
                    );
                }
            }
        }

        return $findings === []
            ? [$this->passing(self::INVALID_HISTORICAL_FX_STATE, 'Posted foreign-currency journal lines are internally consistent with their stored rates.')]
            : $findings;
    }

    /**
     * Does the company transact in a foreign currency without the FX accounts a
     * settlement would need?
     *
     * Legal right up until a foreign settlement is posted, so the finding is a
     * Warning rather than a Fail.
     *
     * @return list<ControlFinding>
     */
    public function foreignSettlementFindings(Company $company): array
    {
        if (! $this->companyCurrency->hasPostedForeignLines($company)) {
            return [$this->passing(
                self::FOREIGN_SETTLEMENT_ACCOUNTS,
                'The company has no foreign-currency activity, so realised FX accounts are not required.',
            )];
        }

        if (! $this->realizedFx->canPostForeignSettlement($company)) {
            return [$this->finding(
                self::FOREIGN_SETTLEMENT_ACCOUNTS,
                ControlStatus::Warning,
                'The company has posted foreign-currency entries but its realised FX gain and loss accounts are not configured, so a foreign settlement cannot be posted.',
                $company,
            )];
        }

        return [$this->passing(
            self::FOREIGN_SETTLEMENT_ACCOUNTS,
            'The company transacts in a foreign currency and its realised FX accounts are configured.',
        )];
    }

    /**
     * Foreign journal lines posted to an account that declares a different
     * currency.
     *
     * A base-currency line is never mismatched - it makes no denomination claim -
     * so only foreign lines are examined, matching DocumentCurrencyService.
     *
     * @return list<ControlFinding>
     */
    public function accountDocumentCurrencyFindings(Company $company): array
    {
        $findings = [];

        foreach ($this->postedForeignLines($company, ['account']) as $line) {
            $account = $line->account;

            if ($account === null || $account->currency_id === null) {
                continue;
            }

            if ((int) $account->currency_id !== (int) $line->currency_id) {
                $findings[] = $this->lineFinding(
                    self::ACCOUNT_DOCUMENT_CURRENCY,
                    ControlStatus::Fail,
                    sprintf(
                        'Account [%s %s] holds [%s] but a journal line posted to it is transacted in a different currency.',
                        $account->code,
                        $account->name,
                        $account->currency?->code ?? 'another currency',
                    ),
                    $line,
                );
            }
        }

        return $findings === []
            ? [$this->passing(self::ACCOUNT_DOCUMENT_CURRENCY, 'No foreign-currency line is posted to an account that declares a different currency.')]
            : $findings;
    }

    /**
     * Posted lines that claim a transaction currency.
     *
     * @param  list<string>  $with
     * @return Collection<int, JournalLine>
     */
    private function postedForeignLines(Company $company, array $with = [])
    {
        return JournalLine::query()
            ->with($with)
            ->whereNotNull('currency_id')
            ->whereHas('journal', fn ($query) => $this->scopeToPostedCompanyJournals($query, $company))
            ->orderBy('id')
            ->get();
    }

    /**
     * Posted lines that carry ANY foreign metadata, including base lines that
     * wrongly carry some.
     *
     * @return Collection<int, JournalLine>
     */
    private function postedLinesWithFxMetadata(Company $company)
    {
        return JournalLine::query()
            ->where(function ($query): void {
                $query->whereNotNull('currency_id')
                    ->orWhereNotNull('exchange_rate')
                    ->orWhereNotNull('foreign_debit')
                    ->orWhereNotNull('foreign_credit');
            })
            ->whereHas('journal', fn ($query) => $this->scopeToPostedCompanyJournals($query, $company))
            ->orderBy('id')
            ->get();
    }

    private function scopeToPostedCompanyJournals($query, Company $company): void
    {
        $query->where('company_id', $company->id)
            ->where('status', JournalStatus::Posted->value);
    }

    private function finding(
        string $code,
        ControlStatus $status,
        string $description,
        Company $company,
        array $details = [],
    ): ControlFinding {
        return new ControlFinding(
            controlCode: $code,
            status: $status,
            description: $description,
            resourceType: $company->getMorphClass(),
            resourceId: (int) $company->getKey(),
            details: $details,
        );
    }

    private function lineFinding(
        string $code,
        ControlStatus $status,
        string $description,
        JournalLine $line,
    ): ControlFinding {
        return new ControlFinding(
            controlCode: $code,
            status: $status,
            description: $description,
            resourceType: $line->getMorphClass(),
            resourceId: (int) $line->getKey(),
        );
    }

    private function passing(string $code, string $description): ControlFinding
    {
        return new ControlFinding(
            controlCode: $code,
            status: ControlStatus::Pass,
            description: $description,
        );
    }
}
