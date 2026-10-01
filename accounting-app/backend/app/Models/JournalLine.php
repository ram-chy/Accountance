<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\JournalLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one account within a journal.
 *
 * Exactly one of debit/credit carries a value and the other is zero. That shape
 * is enforced three times over: the one_sided CHECK constraint in the database,
 * the validation rules on the journal lines request, and the assertions in
 * JournalService. Three layers look redundant until you consider which ones a
 * future code path will actually use - the database one is the only one that a
 * hand-written INSERT cannot get past.
 */
#[Fillable([
    'account_id',
    'description',
    'debit',
    'credit',
    'line_number',
])]
class JournalLine extends Model
{
    /** @use HasFactory<JournalLineFactory> */
    use HasFactory;

    /**
     * Deliberately NOT 'decimal:4'. Laravel's decimal cast returns a string, but
     * it hands the raw driver string straight through, and a value that has been
     * through a JSON response and back can arrive with a different scale. The
     * amount accessors below normalise through Money instead, so no consumer can
     * accidentally do arithmetic on a raw string.
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function debitAmount(): Money
    {
        return Money::of($this->debit);
    }

    public function creditAmount(): Money
    {
        return Money::of($this->credit);
    }

    public function isDebit(): bool
    {
        return $this->debitAmount()->isPositive();
    }

    public function isCredit(): bool
    {
        return $this->creditAmount()->isPositive();
    }

    /**
     * The single amount on this line, whichever side carries it.
     */
    public function amount(): Money
    {
        return $this->isDebit() ? $this->debitAmount() : $this->creditAmount();
    }
}
