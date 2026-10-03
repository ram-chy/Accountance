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
 *
 * Phase 11 applies that same reasoning to credit and debit notes. A note needs an
 * identifier a user can quote back, so it gets a counter; but all four note types
 * share one, for exactly the reason cash/bank transactions do. "What number was
 * that credit note" has one answer because there is one sequence, and the note's
 * own note_type column says which of the four it was. Four sequences with four
 * prefixes (SCN-/SDN-/PCN-/PDN-) would also work, and were considered; they were
 * rejected because the existing convention is one prefix per counter and one
 * counter per operational table, and this module is one table.
 */
enum DocumentNumberType: string
{
    case Invoice = 'INVOICE';
    case Bill = 'BILL';
    case Receipt = 'RECEIPT';
    case Payment = 'PAYMENT';
    case CashBankTransaction = 'CASH_BANK_TRANSACTION';
    case CreditDebitNote = 'CREDIT_DEBIT_NOTE';

    /*
     * Phase 12 applies the same rule to fixed assets. An asset number is quoted on
     * a physical asset label, in an insurance schedule and on a disposal note, so
     * it is a human-facing identifier and needs to be sequential per company rather
     * than random. One counter for all of them, because there is one table: the
     * category is on the asset's own row, exactly as note_type is on a note's.
     */
    case FixedAsset = 'FIXED_ASSET';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
