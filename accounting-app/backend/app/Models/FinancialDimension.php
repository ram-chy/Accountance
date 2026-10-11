<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialDimension extends Model
{
    use HasFactory;

    /**
     * Only the three fields a user actually types.
     *
     * `company_id` and `is_active` are deliberately absent: the company comes from
     * the authenticated context and a dimension is created active by definition, so
     * neither may be set by anything that fills this model from a request. The
     * service assigns them as plain properties.
     */
    protected $fillable = [
        'type',
        'code',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(FinancialDimensionValue::class);
    }
}
