<?php

namespace App\Http\Requests\Accounting\Reconciliation;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', BankReconciliation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'bank_account_id' => [
                'required',
                'integer',
                Rule::exists(BankAccount::class, 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d'],
            'statement_opening_balance' => ['required', 'numeric'],
            'statement_closing_balance' => ['required', 'numeric'],
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
