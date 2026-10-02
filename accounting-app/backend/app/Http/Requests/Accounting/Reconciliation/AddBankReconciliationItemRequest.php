<?php

namespace App\Http\Requests\Accounting\Reconciliation;

use App\Models\Company;
use App\Models\JournalLine;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddBankReconciliationItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reconciliation = $this->route('reconciliation');

        if ($reconciliation === null) {
            return false;
        }

        return $this->user()->can('update', $reconciliation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'journal_line_id' => [
                'required',
                'integer',
                Rule::exists(JournalLine::class, 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }
}
