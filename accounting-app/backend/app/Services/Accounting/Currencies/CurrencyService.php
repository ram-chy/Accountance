<?php

namespace App\Services\Accounting\Currencies;

use App\Models\Company;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrencyService
{
    public function create(Company $company, array $data): Currency
    {
        return DB::transaction(function () use ($company, $data) {
            $currency = new Currency([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'symbol' => $data['symbol'] ?? null,
                'decimal_precision' => $data['decimal_precision'] ?? 2,
                'is_active' => $data['is_active'] ?? true,
                'is_base' => $data['is_base'] ?? false,
            ]);
            $currency->company_id = $company->getKey();
            $currency->save();

            if ($currency->is_base) {
                Currency::where('company_id', $company->getKey())
                    ->where('id', '!=', $currency->id)
                    ->update(['is_base' => false]);
            }

            return $currency->refresh();
        });
    }

    public function update(Currency $currency, array $data): Currency
    {
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $currency->is_base) {
            throw ValidationException::withMessages([
                'is_active' => 'The base currency cannot be deactivated.',
            ]);
        }

        $currency->fill($data);
        $currency->save();

        return $currency->refresh();
    }

    public function setBase(Currency $currency): Currency
    {
        if (! $currency->is_active) {
            throw ValidationException::withMessages([
                'currency' => 'An inactive currency cannot be set as base currency.',
            ]);
        }

        return DB::transaction(function () use ($currency) {
            Currency::where('company_id', $currency->company_id)
                ->update(['is_base' => false]);

            $currency->is_base = true;
            $currency->save();

            return $currency->refresh();
        });
    }

    public function activate(Currency $currency): Currency
    {
        $currency->is_active = true;
        $currency->save();

        return $currency->refresh();
    }

    public function deactivate(Currency $currency): Currency
    {
        if ($currency->is_base) {
            throw ValidationException::withMessages([
                'currency' => 'The base currency cannot be deactivated.',
            ]);
        }

        $currency->is_active = false;
        $currency->save();

        return $currency->refresh();
    }
}
