<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update the settings of the company in the current context.
 *
 * There is deliberately no company id in the rules: the target is taken from
 * the resolved CompanyContext, so a body containing company_id is ignored and
 * cannot redirect the update at another company.
 */
class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = app(CompanyContext::class)->get();

        if (! $company instanceof Company) {
            return false;
        }

        return $this->user()?->can('updateSettings', $company) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_number_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\/]*$/'],
            'quotation_number_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\/]*$/'],
            'default_payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'default_currency_id' => ['nullable', 'integer', 'min:1'],
            'default_fiscal_year_start_month' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ];
    }

    /**
     * Settings not mentioned in the request are left untouched, so only the
     * keys actually submitted are handed to the service.
     */
    public function settingsPayload(): array
    {
        return array_intersect_key(
            $this->validated(),
            array_flip(array_keys($this->rules())),
        );
    }
}
