<?php

namespace App\Services\Accounting\Currencies;

use App\Models\Company;
use App\Models\ExchangeRate;
use Illuminate\Validation\ValidationException;

class ExchangeRateService
{
    public function create(Company $company, array $data): ExchangeRate
    {
        if ($data['from_currency_id'] === $data['to_currency_id']) {
            throw ValidationException::withMessages([
                'to_currency_id' => 'To currency must be different from from currency.',
            ]);
        }

        $rate = new ExchangeRate([
            'from_currency_id' => $data['from_currency_id'],
            'to_currency_id' => $data['to_currency_id'],
            'effective_date' => $data['effective_date'],
            'rate' => $data['rate'],
            'is_active' => $data['is_active'] ?? true,
            'source' => $data['source'] ?? null,
        ]);
        $rate->company_id = $company->getKey();
        $rate->save();

        return $rate->refresh();
    }

    public function update(ExchangeRate $rate, array $data): ExchangeRate
    {
        $rate->fill($data);
        $rate->save();

        return $rate->refresh();
    }

    public function activate(ExchangeRate $rate): ExchangeRate
    {
        $rate->is_active = true;
        $rate->save();

        return $rate->refresh();
    }

    public function deactivate(ExchangeRate $rate): ExchangeRate
    {
        $rate->is_active = false;
        $rate->save();

        return $rate->refresh();
    }
}
