<?php

namespace App\Models\Traits;

use App\Models\BudgetLineDimension;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasBudgetLineDimensions
{
    public function budgetLineDimensions(): HasMany
    {
        return $this->hasMany(BudgetLineDimension::class);
    }
}
