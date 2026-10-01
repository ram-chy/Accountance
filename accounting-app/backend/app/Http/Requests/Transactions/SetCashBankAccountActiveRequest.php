<?php

namespace App\Http\Requests\Transactions;

use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Activate or deactivate an account's bank details.
 *
 * No body. The action is the path, exactly as for a customer's deactivate, so
 * there is no payload through which a client could set an unrelated field at the
 * same time.
 *
 * A direct permission check rather than a policy method: Account already has
 * AccountPolicy, and a policy is resolved per model class, so a second one for
 * Account could never be reached. See UpdateCashBankAccountRequest.
 */
class SetCashBankAccountActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(PermissionName::CashBankUpdate->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
