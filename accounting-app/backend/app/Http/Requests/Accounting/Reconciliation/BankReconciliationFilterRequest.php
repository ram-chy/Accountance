<?php

namespace App\Http\Requests\Accounting\Reconciliation;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankReconciliationFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', BankReconciliation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'bank_account_id' => [
                'nullable',
                'integer',
                Rule::exists(BankAccount::class, 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'status' => ['nullable', 'string', 'in:DRAFT,IN_PROGRESS,RECONCILED'],
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }
}
