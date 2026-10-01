<?php

namespace App\Http\Requests;

use App\Enums\PermissionName;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Manages membership of the company in the route.
 *
 * The target user id is taken from the body but re-checked against the company
 * in the route, and the membership state itself is read from the database, so
 * the caller cannot grant themselves access to a company by supplying a
 * different id.
 */
class ManageCompanyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $company instanceof Company) {
            return false;
        }

        /*
         * The ability passed to can() is the POLICY method name, not the
         * permission name. Spatie registers each permission as a Gate ability
         * in its own right, so Gate::check('companies.update', $company) would
         * answer purely from the permission table and CompanyPolicy would never
         * run - silently skipping the membership check.
         */
        return $this->user()?->can('update', $company) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Setting is_default on an inbound member silently changes that user's
        // active company on their next request, so it is an administrative
        // action rather than part of adding a member. Callers without the
        // permission get a hard 403 instead of the flag being silently
        // dropped, which would leave them believing it had been applied.
        if ($this->boolean('is_default') && ! $this->mayChangeDefaults()) {
            $this->failedAuthorization();
        }

        $this->merge([
            'is_default' => $this->boolean('is_default'),
        ]);
    }

    /**
     * Whether the caller may choose another user's default company.
     */
    private function mayChangeDefaults(): bool
    {
        return $this->user()?->can(PermissionName::CompaniesDelete) === true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $company = $this->route('company');

            if (! $company instanceof Company) {
                return;
            }

            $userId = (int) $this->input('user_id');

            // After-hooks run even when other rules already failed, so the
            // user is looked up defensively: User::find() returns null for an
            // id that does not exist, and passing that to hasMember() would
            // raise a TypeError and surface as a 500 instead of the 422 the
            // exists:users,id rule has already recorded.
            $user = $userId ? User::find($userId) : null;

            if ($user !== null && $company->hasMember($user)) {
                $validator->errors()->add(
                    'user_id',
                    'This user already belongs to the selected company.',
                );
            }

            if ($this->boolean('is_default') && ! $company->is_active) {
                $validator->errors()->add(
                    'is_default',
                    'An inactive company cannot be a default company.',
                );
            }
        });
    }
}
