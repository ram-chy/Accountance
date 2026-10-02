<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One effective-dated percentage for one tax.
 *
 * A percentage, not a fraction: `rate` holds 10.0000 for 10%, matching the
 * `tax_rate` column already on sales_invoice_lines and purchase_bill_lines. One
 * quantity, one convention - a rate a human reads as 0.1 in one table and 10 in
 * another is a rate that will eventually be read wrong.
 *
 * `effective_to` is null on an open-ended rate rather than holding a far-future
 * sentinel, because a sentinel is a value someone has to choose correctly.
 */
#[Fillable([
    'tax_id',
    'rate',
    'effective_from',
    'effective_to',
])]
class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use HasFactory;

    /**
     * No 'decimal:4' cast, for the reason JournalLine documents: Laravel hands
     * the raw driver string straight through and a value that has been through a
     * JSON response and back can arrive with a different scale. rateAmount()
     * normalises through Money so no caller can do arithmetic on a bare string.
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function rateAmount(): Money
    {
        return Money::of($this->rate);
    }

    /**
     * Does this rate apply on a given day?
     *
     * Inclusive at both ends, and the reason a rate ending 2026-03-31 and one
     * starting 2026-04-01 leave no unowned day between them. `active` is passed
     * in rather than read from the model so the caller decides whether an
     * inactive rate is being asked about as a candidate or merely counted.
     */
    public function isEffectiveOn(Carbon|string $date, bool $requireActive = true): bool
    {
        if ($requireActive && ! $this->is_active) {
            return false;
        }

        $day = $date instanceof Carbon ? $date->toDateString() : $date;

        if ($this->effective_from->toDateString() > $day) {
            return false;
        }

        return $this->effective_to === null
            || $this->effective_to->toDateString() >= $day;
    }

    /**
     * Does this rate's period intersect another rate's?
     *
     * An open-ended period is treated as running to infinity, so an open-ended
     * rate overlaps every later one. This is what makes "add 12% from April while
     * 10% is still open-ended" a refusal rather than two rows the resolver would
     * have to break a tie between - and a tie broken the wrong way is a wrong tax
     * figure on an invoice.
     */
    public function overlaps(TaxRate $other): bool
    {
        $thisEnds = $this->effective_to?->toDateString();
        $otherEnds = $other->effective_to?->toDateString();

        $thisStartsBeforeOtherEnds = $otherEnds === null
            || $this->effective_from->toDateString() <= $otherEnds;

        $otherStartsBeforeThisEnds = $thisEnds === null
            || $other->effective_from->toDateString() <= $thisEnds;

        return $thisStartsBeforeOtherEnds && $otherStartsBeforeThisEnds;
    }
}
