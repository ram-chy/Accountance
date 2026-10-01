<?php

namespace App\Http\Requests;

use App\Enums\RoleName;
use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'mobile_no' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9]{7,20}$/'],
            'password' => ['sometimes', 'nullable', 'string', StrongPassword::class],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(RoleName::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile_no.regex' => 'The mobile number must be 7 to 20 digits and may start with +.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
