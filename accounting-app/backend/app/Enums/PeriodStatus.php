<?php

namespace App\Enums;

/**
 * Accounting period status.
 *
 * A period is OPEN until it is closed. Closing was one-way in Phase 4, when
 * there was no reopen permission and no reopen endpoint; the Phase 4 report
 * recorded the consequence as a period closed by mistake needing a
 * database-level correction. Phase 8 resolves that gap with an explicit,
 * separately authorized reopen operation guarded by
 * `accounting.periods.reopen`, held by Admin only.
 *
 * Two states still: a reopened period is OPEN, not REOPENED, so no reader has to
 * know what a third state means. The history of having been closed is audit
 * information on the period's own close/reopen timestamps, not a status.
 */
enum PeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
