<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLineDimension extends Model
{
    protected $fillable = [
        'budget_line_id',
        'financial_dimension_id',
        'financial_dimension_value_id',
    ];

    protected function casts(): array
    {
        return [
            'budget_line_id' => 'integer',
            'financial_dimension_id' => 'integer',
            'financial_dimension_value_id' => 'integer',
        ];
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function dimension(): BelongsTo
    {
        return $this->belongsTo(FinancialDimension::class, 'financial_dimension_id');
    }

    public function value(): BelongsTo
    {
        return $this->belongsTo(FinancialDimensionValue::class, 'financial_dimension_value_id');
    }
}
