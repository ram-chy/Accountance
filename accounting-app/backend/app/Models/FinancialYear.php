<?php

namespace App\Models;

use App\Enums\FinancialYearStatus;
use Database\Factories\FinancialYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company's fiscal year: the container its accounting periods live in.
 *
 * FinancialYear exists only because AccountingPeriod had no parent. Phase 4
 * could store "January 2027" without knowing which year it belonged to, which is
 * enough to decide whether one date may be posted into. It is not enough to
 * answer "is this company's year finished", because that question is about every
 * month at once and the periods are the things that actually get closed.
 *
 * Both endpoints are inclusive, on the same terms as AccountingPeriod: a date
 * equal to start_date or equal to end_date belongs to this year.
 *
 * The year is a dated container and holds no amounts. Closing it writes no
 * journal, because JournalReportService::retainedEarnings() already derives
 * retained earnings from posted lines through a date, so a closing entry would be
 * counted a second time on the balance sheet.
 */
#[Fillable([
    'name',
    'start_date',
    'end_date',
])]
class FinancialYear extends Model
{
    /** @use HasFactory<FinancialYearFactory> */
    use HasFactory;

    /**
     * `status` is absent for the same reason it is absent on AccountingPeriod:
     * it moves only through FinancialYearService::close(), which validates that
     * every period is closed and holds the row while it changes. Letting a
     * request body set status would mint a closed year without closing it.
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => FinancialYearStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class, 'financial_year_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who closed it. Named `closer` because this relation is only meaningful
     * while the year is closed.
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Do this year's inclusive dates intersect another's?
     *
     * Same interval-intersection form as AccountingPeriod::overlaps(), for the
     * same reason: "starts before the other ends AND ends after the other starts"
     * would flag back-to-back years (2026-04-01..2027-03-31 and
     * 2027-04-01..2028-03-31) as overlapping, and they must not be.
     */
    public function overlaps(self $other): bool
    {
        return $this->start_date->startOfDay()->lessThanOrEqualTo($other->end_date->startOfDay())
            && $this->end_date->startOfDay()->greaterThanOrEqualTo($other->start_date->startOfDay());
    }
}
