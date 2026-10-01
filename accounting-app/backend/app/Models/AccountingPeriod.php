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
 *
 * Phase 8 adds financialYear, so a period now knows which fiscal year owns it.
 * The column is nullable in the schema only so the Phase 8 migration can
 * backfill periods that already existed; every period created or generated from
 * now on carries a year, and AccountingPeriodService::create() requires one.
 */
#[Fillable([
    'name',
    'start_date',
    'end_date',
    'financial_year_id',
])]
class AccountingPeriod extends Model
{
    /** @use HasFactory<AccountingPeriodFactory> */
    use HasFactory;

    /**
     * `status` is absent on purpose: it moves only through
     * AccountingPeriodService::close() and ::reopen(), which validate the
     * transition and hold the row while it happens. `closed_by`/`closed_at` are
     * absent for the same reason and are written by those two methods, never by a
     * request body.
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(FinancialYear::class, 'financial_year_id');
    }

    /**
     * Who closed it. Meaningful only while the period is closed; reopen() clears
     * the pair rather than leaving a stale attribution on an open period.
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Who reopened it, and when. Set by reopen() and cleared again by the next
     * close, so it describes the most recent transition of that kind rather than
     * an attempt log.
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
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
