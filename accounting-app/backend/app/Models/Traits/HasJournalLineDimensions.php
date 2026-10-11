<?php

namespace App\Models\Traits;

use App\Models\JournalLineDimension;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasJournalLineDimensions
{
    public function journalLineDimensions(): HasMany
    {
        return $this->hasMany(JournalLineDimension::class);
    }
}
