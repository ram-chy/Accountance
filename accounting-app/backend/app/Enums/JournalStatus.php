<?php

namespace App\Enums;

/**
 * Journal lifecycle status.
 *
 * Only two states exist. There is no PENDING or PROCESSING, because posting is a
 * single synchronous database transaction - either it committed or it did not,
 * and a status implying "in between" would describe a state that cannot survive
 * a crash without a reconciliation process that this phase does not build.
 */
enum JournalStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
