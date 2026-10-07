<?php

namespace App\Http\Requests\Accounting\Currency;

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Choose or change the company's base (functional) currency.
 *
 * Company-scoped: the target is the context company, and there is no company id in
 * the payload to redirect it. Authorization uses the company's own settings-update
 * ability, which is the permission the phase brief assigns to the base-currency
 * decision.
 *
 * The financial-safety rule - refusing a change that would reinterpret posted data -
 * is enforced by CompanyCurrencyService, not here, because it requires the company's
 * posted history rather than the shape of the request.
 */
class ChangeBaseCurrencyRequest extends FormRequest
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
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
        ];
    }
}
