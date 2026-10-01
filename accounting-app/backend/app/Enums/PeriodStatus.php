<?php

namespace App\Enums;

/**
 * Accounting period status.
 *
 * A period is OPEN until it is closed. Closing is one-way in Phase 4: there is
 * no reopen permission and no reopen endpoint, so a closed period cannot be
 * reopened by any user. That is deliberate - reopening is a privileged act that
 * should require an explicit authority, and this phase does not create one. See
 * the Phase 4 report under "Known Limitations" for the consequence: a period
 * closed by mistake needs a database-level correction by an administrator.
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
