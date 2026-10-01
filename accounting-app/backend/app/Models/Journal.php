<?php

namespace App\Models;

use App\Enums\JournalSource;
use App\Enums\JournalStatus;
use App\Support\Money;
use Database\Factories\JournalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A journal entry: the header of one double-entry transaction.
 *
 * The account detail lives in journal_lines. A header row carries no amount
 * columns of its own, which is what keeps there being exactly one place in the
 * system where monetary figures are stored. Totals are derived, never cached in
 * a column that could disagree with the lines.
 */
#[Fillable([
    'journal_date',
    'description',
    'reference',
    'source_type',
    'source_id',
])]
class Journal extends Model
{
    /** @use HasFactory<JournalFactory> */
    use HasFactory;

    /**
     * Absent from fillable, and each absence is deliberate:
     *
     *   status      - only JournalPostingService may set it to POSTED, inside a
     *                 locked transaction. Allowing a request body to set status
     *                 would let anyone mint posted history without posting.
     *   journal_number - allocated by JournalNumberSequence, never client-chosen.
     *   created_by  - comes from the authenticated user, not the payload.
     *   posted_by / posted_at - the entire audit record of the post operation,
     *                 set from the authenticated user at post time.
     */
    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'status' => JournalStatus::class,
            'source_type' => JournalSource::class,
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_id')->orderBy('line_number');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user who posted this journal. Named poster() because posted_by is the
     * column and a plain poster() relation is easier to read at the call site
     * than creator()->wherePivot().
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The period this journal's accounting date falls into, if any exists.
     *
     * Null is a legitimate answer: a draft may legitimately be dated before any
     * period has been opened. Posting is where a missing period becomes an
     * error, not here.
     */
    public function period(): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('company_id', $this->company_id)
            ->whereDate('start_date', '<=', $this->journal_date)
            ->whereDate('end_date', '>=', $this->journal_date)
            ->first();
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    public function isPosted(): bool
    {
        return $this->status->isPosted();
    }

    public function totalDebit(): Money
    {
        return $this->lines->reduce(
            fn (Money $carry, JournalLine $line) => $carry->plus($line->debitAmount()),
            Money::zero()
        );
    }

    public function totalCredit(): Money
    {
        return $this->lines->reduce(
            fn (Money $carry, JournalLine $line) => $carry->plus($line->creditAmount()),
            Money::zero()
        );
    }

    /**
     * Is this journal balanced?
     *
     * Computed from the loaded lines, so the caller controls freshness. The
     * posting service deliberately re-reads lines inside its transaction rather
     * than trusting a value computed before the lock was taken.
     */
    public function isBalanced(): bool
    {
        return $this->totalDebit()->equals($this->totalCredit());
    }

    /**
     * Accounts referenced by this journal's lines, distinct.
     *
     * Used to validate that every account belongs to the journal's company.
     */
    public function accounts(): Collection
    {
        return Account::query()
            ->whereIn('id', $this->lines->pluck('account_id'))
            ->get();
    }
}
