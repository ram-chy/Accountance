<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialDimensionValue extends Model
{
    use HasFactory;

    /**
     * Only the two fields a user actually types; the parent dimension and the
     * active flag are assigned by the service, never filled from a request.
     */
    protected $fillable = [
        'code',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'financial_dimension_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(FinancialDimension::class, 'financial_dimension_id');
    }

    public function journalLineDimensions(): HasMany
    {
        return $this->hasMany(JournalLineDimension::class, 'financial_dimension_value_id');
    }

    public function budgetLineDimensions(): HasMany
    {
        return $this->hasMany(BudgetLineDimension::class, 'financial_dimension_value_id');
    }
}
