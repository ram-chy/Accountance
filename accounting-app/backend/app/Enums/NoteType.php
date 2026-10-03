<?php

namespace App\Enums;

/**
 * The four kinds of credit/debit note.
 *
 * WHY A NEW ENUM RATHER THAN A REUSE
 *
 * The brief says not to invent a second lifecycle enum, and that rule is obeyed -
 * notes reuse TransactionStatus for DRAFT/POSTED. It says nothing about the
 * document's *kind*, and no existing enum carries one: TransactionStatus is a
 * lifecycle, DocumentNumberType is a numbering counter, JournalSource is a ledger
 * provenance tag. There is nothing to reuse, so this is a new fact about the
 * document rather than a duplicate of an existing one.
 *
 * FOUR CASES AND NOT TWO
 *
 * The obvious reduction is Credit / Debit, with the sales-or-purchase distinction
 * read off whichever source document is attached. That collapses two independent
 * axes into a nullable pair of columns: `sales_invoice_id` and `purchase_bill_id`
 * would each have to be interpreted through the note_type, and a row with both
 * set or both null would have no meaning at all. Encoding the cross product in the
 * enum makes the column self-describing and makes every downstream question a
 * method call rather than a case analysis:
 *
 *   isSales()      - does this adjust an invoice or a bill
 *   isCredit()     - does it reduce or increase what the counterparty owes
 *   isOutputTax()  - which side of the tax engine it must use
 *
 * The last one is the one that matters for correctness. A sales credit note
 * reverses OUTPUT tax and a purchase credit note reverses INPUT tax, and getting
 * that backwards would post a credit to the wrong side of the tax account. Having
 * one method own the answer is the reason for the enum.
 *
 * THE SIGN CONVENTION, STATED ONCE
 *
 * CREDIT always reduces the counterparty's balance: a sales credit note credits
 * revenue and credits the customer's receivable, a purchase credit note debits
 * the supplier's payable. DEBIT does the opposite in both worlds. The adjustment
 * services and the posting service both read isCredit() rather than deriving the
 * direction from the source document type, so the two can never disagree.
 */
enum NoteType: string
{
    case SalesCreditNote = 'SALES_CREDIT_NOTE';
    case SalesDebitNote = 'SALES_DEBIT_NOTE';
    case PurchaseCreditNote = 'PURCHASE_CREDIT_NOTE';
    case PurchaseDebitNote = 'PURCHASE_DEBIT_NOTE';

    /**
     * Adjusts a sales invoice.
     */
    public function isSales(): bool
    {
        return $this === self::SalesCreditNote || $this === self::SalesDebitNote;
    }

    /**
     * Adjusts a purchase bill.
     */
    public function isPurchase(): bool
    {
        return ! $this->isSales();
    }

    /**
     * Reduces what the counterparty owes.
     */
    public function isCredit(): bool
    {
        return $this === self::SalesCreditNote || $this === self::PurchaseCreditNote;
    }

    /**
     * Increases what the counterparty owes.
     */
    public function isDebit(): bool
    {
        return ! $this->isCredit();
    }

    /**
     * Which tax side this document uses.
     *
     * Sales charges tax, so a sales note charges OUTPUT tax and reverses it.
     * Purchases recover tax, so a purchase note recovers INPUT tax. Equivalent to
     * isSales(), and named for the tax engine's vocabulary because that is the
     * only caller that needs it - DocumentTaxContext is constructed from it.
     */
    public function isOutputTaxSide(): bool
    {
        return $this->isSales();
    }

    /**
     * The sign this note contributes to a source document's adjusted total.
     *
     * Credits are negative, debits positive. Exposed as a string rather than an
     * int so it cannot be multiplied into a Money by accident; callers pass it
     * through the adjustment service, which does the arithmetic in exact decimal.
     */
    public function signModifier(): string
    {
        return $this->isCredit() ? '-1' : '1';
    }

    /**
     * A human label, used in journal descriptions and validation messages so a
     * user is never shown "SALES_CREDIT_NOTE" without explanation.
     */
    public function label(): string
    {
        return match ($this) {
            self::SalesCreditNote => 'Sales credit note',
            self::SalesDebitNote => 'Sales debit note',
            self::PurchaseCreditNote => 'Purchase credit note',
            self::PurchaseDebitNote => 'Purchase debit note',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function salesValues(): array
    {
        return [self::SalesCreditNote->value, self::SalesDebitNote->value];
    }

    /**
     * @return array<int, string>
     */
    public static function purchaseValues(): array
    {
        return [self::PurchaseCreditNote->value, self::PurchaseDebitNote->value];
    }
}
