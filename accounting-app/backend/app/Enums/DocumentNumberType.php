<?php

namespace App\Enums;

/**
 * Document types whose numbers are allocated via a per-company counter.
 *
 * The counter table uses this set to distinguish between invoices, bills,
 * receipts and payments. Kept as an enum rather than plain strings in the
 * application so no caller can invent a new document type without adding it
 * in one place.
 */
enum DocumentNumberType: string
{
    case Invoice = 'INVOICE';
    case Bill = 'BILL';
    case Receipt = 'RECEIPT';
    case Payment = 'PAYMENT';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
