<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use Carbon\Carbon;
use Database\Factories\AccountingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A date range in which journals may be posted.
 *
 * Both endpoints are inclusive: a journal dated 2027-01-01 or 2027-01-31 falls
 * in a period running 2027-01-01 to 2027-01-31. Treating them as inclusive is
 * what allows consecutive periods (January then February) not to be reported as
 * overlapping, since 2027-01-31 and 2027-02-01 do not intersect.
 */
#[Fillable([
    'name',
    'start_date',
    'end_date',
])]
class AccountingPeriod extends Model
{
    /** @use HasFactory<AccountingPeriodFactory> */
    use HasFactory;

    /**
     * `status` is absent on purpose: it moves only through
     * AccountingPeriodService::close(), which validates that the period is open
     * and holds the row while it changes.
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => PeriodStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class, 'journal_date', 'start_date', 'end_date');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function contains(\DateTimeInterface $date): bool
    {
        $day = Carbon::instance($date)->startOfDay();

        return $day->betweenIncluded(
            Carbon::instance($this->start_date)->startOfDay(),
            Carbon::instance($this->end_date)->startOfDay()
        );
    }

    /**
     * Do this period's inclusive dates intersect another's?
     *
     * Written as interval intersection rather than "starts before this ends and
     * ends after this starts", which is the form that wrongly flags back-to-back
     * periods as overlapping.
     */
    public function overlaps(self $other): bool
    {
        return $this->start_date->startOfDay()->lessThanOrEqualTo($other->end_date->startOfDay())
            && $this->end_date->startOfDay()->greaterThanOrEqualTo($other->start_date->startOfDay());
    }
}
