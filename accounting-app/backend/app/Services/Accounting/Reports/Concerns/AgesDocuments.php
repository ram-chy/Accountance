<?php

namespace App\Services\Accounting\Reports\Concerns;

use Illuminate\Support\Carbon;

/**
 * Aging arithmetic shared by the receivables, payables and aging reports.
 *
 * Buckets are read from config so a company can re-cut them without a code
 * change, and the first bucket deliberately has a null `min` so a not-yet-due
 * document (negative or zero days past due) lands in "current". Getting that
 * wrong is the classic aging bug: an invoice due next week appearing in an
 * "overdue" bucket because its age was passed through as a negative number.
 */
trait AgesDocuments
{
    /**
     * Whole days past the due date, floored at zero.
     */
    protected function daysPastDue(?Carbon $dueDate, Carbon $asOf): int
    {
        if ($dueDate === null) {
            return 0;
        }

        $days = $dueDate->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay(), false);

        return $days > 0 ? (int) $days : 0;
    }

    /**
     * @return array<int, array{key: string, label: string, min: int|null, max: int|null}>
     */
    protected function agingBuckets(): array
    {
        return config('accounting.reports.aging_buckets');
    }

    /**
     * The bucket key a whole-day age falls into, or null if none matches.
     */
    protected function bucketKeyFor(int $daysPastDue): ?string
    {
        foreach ($this->agingBuckets() as $bucket) {
            $min = $bucket['min'];
            $max = $bucket['max'];

            if (($min === null || $daysPastDue >= $min) && ($max === null || $daysPastDue <= $max)) {
                return $bucket['key'];
            }
        }

        return null;
    }
}
