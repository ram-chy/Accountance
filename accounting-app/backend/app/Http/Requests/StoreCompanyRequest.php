<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    use ValidatesCompany;

    public function authorize(): bool
    {
        // Policy ability plus the class, so the check goes through
        // CompanyPolicy::create rather than matching a permission name
        // directly. Both resolve to the same permission; the policy route keeps
        // company authorization in one place.
        return $this->user()?->can('create', Company::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->companyRules(creating: true);
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseCompanyInput(creating: true);
    }
}
