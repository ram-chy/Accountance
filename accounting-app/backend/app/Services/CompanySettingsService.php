<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\QueryException;

/**
 * Read and update per-company settings.
 *
 * Settings always belong to the company resolved by CompanyContext. This
 * service never accepts a company id from the caller, so a settings update
 * cannot be pointed at another company by tampering with a request body.
 */
class CompanySettingsService
{
    /**
     * Fetch the settings row for a company, creating defaults if it is missing.
     *
     * The create is idempotent; the unique index on company_id is the
     * authority if two requests race.
     */
    public function for(Company $company): CompanySetting
    {
        $settings = $company->settings()->first();

        if ($settings) {
            return $settings;
        }

        try {
            return $company->settings()->create();
        } catch (QueryException) {
            return $company->settings()->firstOrFail();
        }
    }

    /**
     * Apply validated settings values.
     *
     * Only keys present in $values are written, so a partial update leaves the
     * remaining settings untouched.
     */
    public function update(Company $company, array $values): CompanySetting
    {
        $settings = $this->for($company);

        $settings->fill($values)->save();

        return $settings->refresh();
    }
}
