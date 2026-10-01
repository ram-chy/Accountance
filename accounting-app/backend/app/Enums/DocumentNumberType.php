<?php

namespace App\Enums;

/**
 * Document types whose numbers are allocated via a per-company counter.
 *
 * The counter table uses this set to distinguish between invoices, bills,
 * receipts and payments. Kept as an enum rather than plain strings in the
 * application so no caller can invent a new document type without adding it
 * in one place.
 *
 * Phase 7 adds CashBankTransaction for the same reason and on the same terms: a
 * cash/bank transaction is a document a user can refer to over the phone, so it
 * needs an identifier that is unique per company and never reused. It shares one
 * counter across deposits, withdrawals and transfers, because a single sequence
 * is what makes "what number was that transfer" answerable - three separate
 * sequences would give three different answers.
 */
enum DocumentNumberType: string
{
    case Invoice = 'INVOICE';
    case Bill = 'BILL';
    case Receipt = 'RECEIPT';
    case Payment = 'PAYMENT';
    case CashBankTransaction = 'CASH_BANK_TRANSACTION';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
