<?php

namespace App\Enums;

/**
 * Lifecycle status for customer/supplier documents that can be paid.
 *
 * Invoices and bills share the same set: they begin as DRAFT, become POSTED
 * once their accounting journal is created, and then move to PARTIALLY_PAID or
 * PAID as allocations are applied. This is distinct from JournalStatus because
 * "PAID" means something to the business document, not to the journal itself.
 */
enum TransactionStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    public function isPartiallyPaid(): bool
    {
        return $this === self::PartiallyPaid;
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isSettled(): bool
    {
        return $this === self::PartiallyPaid || $this === self::Paid;
    }

    public function isFinanciallyClosed(): bool
    {
        return $this === self::Paid;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
