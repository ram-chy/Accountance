<?php

namespace App\Models;

use Database\Factories\CompanySettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company configuration for the modules implemented so far.
 *
 * Deliberately typed columns rather than a key/value or JSON store: every field
 * here has a known shape and must be validated as that shape. Settings that are
 * genuinely open-ended (document preferences, notification preferences) will be
 * added in the phase that needs them, as columns where they are structured and
 * as JSON only where they truly are not.
 */
#[Fillable([
    'invoice_number_prefix',
    'quotation_number_prefix',
    'default_payment_terms_days',
    'default_currency_id',
    'default_fiscal_year_start_month',
])]
class CompanySetting extends Model
{
    /** @use HasFactory<CompanySettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'default_payment_terms_days' => 'integer',
            'default_currency_id' => 'integer',
            'default_fiscal_year_start_month' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
