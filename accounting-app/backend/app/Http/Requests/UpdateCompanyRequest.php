<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    use ValidatesCompany;

    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $company instanceof Company) {
            return false;
        }

        // Policy ability, so membership is checked before the permission.
        return $this->user()?->can('update', $company) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->companyRules(creating: false);
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseCompanyInput(creating: false);
    }
}
