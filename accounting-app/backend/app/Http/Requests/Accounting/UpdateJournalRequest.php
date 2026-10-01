<?php

namespace App\Http\Requests\Accounting;

use App\Models\Company;
use App\Models\Journal;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update a draft journal.
 *
 * Every field is `sometimes`, so a partial update is possible. `lines` in
 * particular may be omitted to change only the header; when it IS supplied it
 * replaces the whole line set, which is the semantics accountants expect when
 * they correct an entry.
 */
class UpdateJournalRequest extends FormRequest
{
    use ValidatesJournalLines;

    public function authorize(): bool
    {
        /** @var Journal $journal */
        $journal = $this->route('journal');

        return $this->user()->can('update', $journal);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'journal_date' => ['sometimes', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        /*
         * Line rules only apply when lines are present. Rebuilding the array
         * conditionally keeps `lines.min` from firing on a header-only update.
         */
        if ($this->has('lines')) {
            $rules = array_merge($rules, $this->journalLineRules());
        }

        return $rules;
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }
}
