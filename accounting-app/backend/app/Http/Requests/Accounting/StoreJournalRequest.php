<?php

namespace App\Http\Requests\Accounting;

use App\Models\Company;
use App\Models\Journal;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create a draft journal in the active company.
 *
 * Deliberately accepts no company_id, no journal_number and no status: those are
 * assigned by the server from the authenticated context and the numbering
 * sequence. There is no rule to forget them under, because the parameters do not
 * exist in the validated payload.
 */
class StoreJournalRequest extends FormRequest
{
    use ValidatesJournalLines;

    public function authorize(): bool
    {
        return $this->user()->can('create', Journal::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->journalLineRules(), [
            'journal_date' => ['required', 'date_format:Y-m-d'],

            /*
             * No `after:today` rule. A journal may legitimately be dated in the
             * past - backdated entries are normal bookkeeping - and the period
             * check at posting time is what actually governs whether the date is
             * acceptable. Rejecting past dates here would prevent legitimate
             * history entry without protecting any invariant.
             */
            'description' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.min' => 'A journal must have at least two lines.',

            /*
             * The bound quoted here is the input bound, not the storage scale.
             * Amounts carrying more decimals than the ledger stores are rounded
             * half-up rather than rejected (config/accounting.php, "Decimal Input
             * Tolerance"), so the message must not claim a hard four-decimal limit
             * the request layer does not enforce - it would tell a user their
             * amount is invalid when it is about to be accepted and rounded.
             */
            'lines.*.debit.decimal' => 'The debit amount must be a number with at most '
                .$this->maxInputDecimals().' decimal places.',
            'lines.*.credit.decimal' => 'The credit amount must be a number with at most '
                .$this->maxInputDecimals().' decimal places.',
        ];
    }
}
