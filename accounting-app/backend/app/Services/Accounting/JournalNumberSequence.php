<?php

namespace App\Services\Accounting;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the next journal number for a company.
 *
 * Numbers are never reused. That constraint drives the whole design: a counter
 * row that only moves forward, allocated inside a transaction with a row lock.
 *
 * The obvious alternative - SELECT MAX(journal_number) + 1 - is wrong in two
 * ways that matter. It reuses numbers after a delete, and two concurrent
 * creations can read the same MAX and issue the same number. Locking the
 * counter row serialises allocation, so the second transaction waits and then
 * reads the already-incremented value.
 */
class JournalNumberSequence
{
    /**
     * Allocate and reserve the next number for this company.
     *
     * MUST be called inside a transaction. The lock is held until that
     * transaction commits or rolls back, which is what makes the read-then-write
     * safe. Calling it outside one would release the lock immediately and
     * reintroduce the race it exists to prevent - the row lock would be held for
     * the duration of the statement and nothing else.
     */
    public function nextFor(Company $company): string
    {
        $this->ensureRowExists($company);

        /*
         * lockForUpdate() takes an exclusive row lock, so a concurrent request
         * for the same company blocks here until this transaction finishes. The
         * read that follows is therefore guaranteed to be fresh.
         */
        $lastNumber = (int) DB::table('journal_number_sequences')
            ->where('company_id', $company->getKey())
            ->lockForUpdate()
            ->value('last_number');

        $next = $lastNumber + 1;

        DB::table('journal_number_sequences')
            ->where('company_id', $company->getKey())
            ->update(['last_number' => $next, 'updated_at' => now()]);

        return $this->format($next);
    }

    /**
     * Create the counter row on first use.
     *
     * insertOrIgnore rather than firstOrCreate because of a genuine race: two
     * first-ever journals for the same company can both find no row. The unique
     * constraint on company_id decides the winner, and the loser proceeds to read
     * the row the winner just inserted. firstOrCreate would raise a duplicate-key
     * error here instead.
     *
     * IGNORE does also swallow other errors, so the caller must handle a missing
     * row on the subsequent locked read rather than assuming it exists. The read
     * below does exactly that.
     */
    private function ensureRowExists(Company $company): void
    {
        DB::table('journal_number_sequences')->insertOrIgnore([
            'company_id' => $company->getKey(),
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Render a number as JNL-000001.
     *
     * Zero padding to a fixed width means lexical ordering matches numeric
     * ordering, so a report listing journals by number as text sorts correctly.
     */
    public function format(int $number): string
    {
        $prefix = (string) config('accounting.journal_number.prefix', 'JNL-');
        $padding = (int) config('accounting.journal_number.padding', 6);

        return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }

    /**
     * The current high-water mark for a company, without allocating.
     *
     * Returns 0 when the company has never created a journal.
     */
    public function currentFor(Company $company): int
    {
        return (int) DB::table('journal_number_sequences')
            ->where('company_id', $company->getKey())
            ->value('last_number');
    }
}
