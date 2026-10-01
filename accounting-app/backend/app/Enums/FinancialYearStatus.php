<?php

namespace App\Enums;

/**
 * Financial-year status.
 *
 * Two states only. A year is OPEN until every one of its periods is closed and
 * the year is closed in its own right; closing the periods is the real work, and
 * the year status is the statement that the year as a whole is finished.
 *
 * There is deliberately no REOPENED. A year that returns to OPEN is simply OPEN
 * again, the same way a period is; a separate state would mean every reader had
 * to know it meant "open, and previously closed".
 */
enum FinancialYearStatus: string
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
