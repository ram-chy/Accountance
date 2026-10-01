<?php

namespace App\Http\Resources;

use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only representation of company settings that may leave the API.
 *
 * Fields are listed explicitly so no future column is exposed by accident.
 */
class CompanySettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CompanySetting $settings */
        $settings = $this->resource;

        return [
            'id' => $settings->id,
            'company_id' => $settings->company_id,
            'invoice_number_prefix' => $settings->invoice_number_prefix,
            'quotation_number_prefix' => $settings->quotation_number_prefix,
            'default_payment_terms_days' => $settings->default_payment_terms_days,
            // Reserved for the fiscal period and currency phase; no currency
            // data is fabricated here.
            'default_currency_id' => $settings->default_currency_id,
            'default_fiscal_year_start_month' => $settings->default_fiscal_year_start_month,
            'created_at' => $settings->created_at?->toIso8601String(),
            'updated_at' => $settings->updated_at?->toIso8601String(),
        ];
    }
}
