<?php

namespace App\Http\Requests;

use App\Config\DateFormats;
use App\Rules\CountryCode;
use App\Rules\Timezone;
use Illuminate\Validation\Rule;

/**
 * Shared company field validation.
 *
 * The create and update requests both use these rules so the two endpoints
 * cannot drift apart. Optional fields default to the application's documented
 * fallbacks, so a company always ends up with a usable timezone and date
 * format even when the client omits them.
 */
trait ValidatesCompany
{
    /**
     * @return array<string, mixed>
     */
    protected function companyRules(bool $creating): array
    {
        return [
            'name' => $this->nameRules($creating),
            'legal_name' => ['nullable', 'string', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:100'],

            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9()\s.-]{7,30}$/'],
            'website' => ['nullable', 'string', 'url:http,https', 'max:255'],

            'address_line_1' => ['nullable', 'string', 'max:200'],
            'address_line_2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],

            'country_code' => ['required', 'string', 'size:2', new CountryCode],
            'timezone' => ['required', 'string', new Timezone],
            'date_format' => ['required', 'string', Rule::in(DateFormats::values())],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function nameRules(bool $creating): array
    {
        $rules = ['required', 'string', 'min:1', 'max:150'];

        if ($creating) {
            return $rules;
        }

        return array_merge($rules, [
            Rule::unique('companies', 'name')->ignore(
                $this->route('company')?->getKey(),
            ),
        ]);
    }

    /**
     * Normalise incoming values so validation sees canonical forms.
     */
    protected function normaliseCompanyInput(bool $creating): void
    {
        $this->merge(array_filter([
            'name' => $this->cleanString('name'),
            'legal_name' => $this->cleanString('legal_name'),
            'registration_number' => $this->cleanString('registration_number'),
            'tax_number' => $this->cleanString('tax_number'),
            'email' => $this->cleanString('email', true),
            'phone' => $this->cleanString('phone'),
            'website' => $this->cleanString('website'),
            'address_line_1' => $this->cleanString('address_line_1'),
            'address_line_2' => $this->cleanString('address_line_2'),
            'city' => $this->cleanString('city'),
            'state' => $this->cleanString('state'),
            'postal_code' => $this->cleanString('postal_code'),
            'country_code' => $this->cleanString('country_code') !== null
                ? strtoupper(trim((string) $this->input('country_code')))
                : null,
        ], fn ($value) => $value !== null));

        if ($creating) {
            $this->merge(array_filter([
                'country_code' => $this->input('country_code', 'US'),
                'timezone' => $this->input('timezone', 'UTC'),
                'date_format' => $this->input('date_format', DateFormats::DEFAULT),
            ], fn ($value) => $value !== null));
        }
    }

    private function cleanString(string $key, bool $lower = false): ?string
    {
        if (! $this->has($key) || ! is_string($this->input($key))) {
            return null;
        }

        $value = trim($this->input($key));

        if ($value === '') {
            return null;
        }

        return $lower ? mb_strtolower($value) : $value;
    }
}
