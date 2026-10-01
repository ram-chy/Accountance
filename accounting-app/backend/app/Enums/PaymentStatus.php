<?php

namespace App\Enums;

/**
 * Status for a payment/receipt document.
 *
 * Payments are simpler than invoices: they are either DRAFT or POSTED. Once
 * posted they have created their accounting journal and their allocations are
 * part of the permanent record. They do not track "paid" - that concept belongs
 * to the documents they are paying.
 */
enum PaymentStatus: string
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
