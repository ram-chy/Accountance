<?php

namespace App\Http\Requests\Accounting\Currency;

use App\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Configure the account pair that realised FX gains and losses post to.
 *
 * Both fields are required to be present - a client clears the configuration by
 * sending both as null, not by omitting one - so "set the gain account and leave the
 * loss account alone" cannot produce the half-configured state the database refuses.
 * The service re-checks pairing and validates account ownership and type.
 *
 * Authorization is a direct permission check because the setting is resolved from
 * CompanyContext rather than a route binding, so there is no bound model to pass to
 * a policy method. Membership was already established by the company.context
 * middleware for that same company.
 */
class UpdateCompanyFxSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(PermissionName::FxUpdate->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'realized_gain_account_id' => ['present', 'nullable', 'integer'],
            'realized_loss_account_id' => ['present', 'nullable', 'integer'],
        ];
    }
}
