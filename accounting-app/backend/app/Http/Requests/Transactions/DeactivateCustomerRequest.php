<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deactivate a customer.
 *
 * A dedicated endpoint with a dedicated permission rather than a PATCH, because
 * deactivation is not an edit. It is the one customer change that is refused once
 * the customer appears on a posted document, it must be distinguishable in an
 * audit log from a routine name correction, and `customers.deactivate` exists as
 * a grant precisely so it can be withheld from anyone who may still correct a
 * typo.
 *
 * There is no `is_active` rule, and there is no reactivate endpoint. The rule
 * that actually refuses the operation - "this customer has posted invoices" -
 * lives in CustomerService::deactivate, where it can be reached from any caller
 * rather than only from HTTP.
 */
class DeactivateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deactivate', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
