<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    protected $model = CompanySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'invoice_number_prefix' => 'INV-',
            'quotation_number_prefix' => 'QUO-',
            'default_payment_terms_days' => 30,
            'default_currency_id' => null,
            'default_fiscal_year_start_month' => 1,
        ];
    }
}
