<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only representation of a company that may leave the API.
 *
 * Membership counts and internal pivot bookkeeping are not exposed; the
 * caller's own relationship to the company is reported as a single boolean
 * because that is the fact a client legitimately needs.
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;

        $isDefault = $request->user()
            ? $company->isDefaultFor($request->user())
            : false;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'registration_number' => $company->registration_number,
            'tax_number' => $company->tax_number,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'address' => [
                'line_1' => $company->address_line_1,
                'line_2' => $company->address_line_2,
                'city' => $company->city,
                'state' => $company->state,
                'postal_code' => $company->postal_code,
                'country_code' => $company->country_code,
            ],
            'timezone' => $company->timezone,
            'date_format' => $company->date_format,
            'is_active' => (bool) $company->is_active,
            'is_default' => $isDefault,
            // $this->whenLoaded() belongs to the resource wrapper, not the
            // model, so the relation is forwarded explicitly.
            'settings' => new CompanySettingResource($company->relationLoaded('settings')
                ? $company->settings
                : null),
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }
}
