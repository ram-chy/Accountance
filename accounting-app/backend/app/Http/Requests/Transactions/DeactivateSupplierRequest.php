<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deactivate a supplier.
 *
 * A dedicated endpoint with a dedicated permission, for the same reasons as
 * DeactivateCustomerRequest. The refusal rule lives in SupplierService so it is
 * reachable from any caller, not only from HTTP.
 */
class DeactivateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('deactivate', $this->route('supplier'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
