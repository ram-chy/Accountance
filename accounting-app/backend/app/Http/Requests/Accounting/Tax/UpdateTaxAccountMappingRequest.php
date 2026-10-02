<?php

namespace App\Http\Requests\Accounting\Tax;

use App\Models\Company;
use App\Models\Tax;
use App\Services\Accounting\TransactionAccountResolver;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Point a tax at the accounts its money posts to.
 *
 * PUT with full replacement, so omitted account ids are stored as null. A mapping
 * is a pair of independent settings rather than a merge target: a client sending
 * only `output_account_id` means "the output account is this one and I am not
 * setting an input account", and treating the absent field as "unchanged" would
 * make it impossible to clear a side.
 *
 * Account existence is scoped to the active company here for a fast, field-level
 * error. Suitability - output must be a LIABILITY, input must be an ASSET - is
 * TaxAccountMappingService's job, because it delegates to
 * TransactionAccountResolver and must do so in the service layer where a
 * non-HTTP caller is protected too.
 */
class UpdateTaxAccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateMapping', $this->route('tax'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return [
            'output_account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $company->getKey()),
            ],
            'input_account_id' => [
                'nullable',
                'integer',
                /*
                 * `different` mirrors the check in TaxAccountMappingService and the
                 * CHECK constraint on the table. All three exist for different
                 * readers: this one gives the field-level error, the service one
                 * protects non-HTTP callers, and the constraint one holds if a row
                 * is ever written by something that reaches neither.
                 */
                'different:output_account_id',
                Rule::exists('accounts', 'id')->where('company_id', $company->getKey()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'output_account_id.exists' => 'The selected output account does not exist in the active company.',
            'input_account_id.exists' => 'The selected input account does not exist in the active company.',
            'input_account_id.different' => 'The output and input accounts must be different accounts.',
        ];
    }
}
