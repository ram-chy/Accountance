<?php

namespace App\Enums;

/**
 * Where a journal originated.
 *
 * Phase 4 creates journals by hand, so only the two values that describe manual
 * accounting work are defined:
 *
 *   Manual      - a person entered it in the journal API
 *   Adjustment  - a correcting entry (per the spec's "Adjustment Journal")
 *
 * The column and the pair (source_type, source_id) exist so a business module
 * can stamp its documents onto the journals it produces without a migration.
 *
 * Phase 5 is that phase: the four transactional cases are declared because
 * SalesInvoicePostingService, PurchaseBillPostingService,
 * CustomerReceiptPostingService and SupplierPaymentPostingService each now
 * produce exactly one of them. Each case has a real producer, which is the bar
 * Phase 4 set deliberately.
 *
 * The values name the *document*, not the direction of money: a customer
 * receipt and a supplier payment are both cash movements but are different
 * documents with different source rows, and collapsing them would make
 * source_id ambiguous.
 *
 * A null source_type means "manual" for backwards-compatible reading, but the
 * Journal model always writes Manual explicitly so the column never has to
 * carry two meanings for one concept.
 */
enum JournalSource: string
{
    case Manual = 'MANUAL';
    case Adjustment = 'ADJUSTMENT';
    case SalesInvoice = 'SALES_INVOICE';
    case PurchaseBill = 'PURCHASE_BILL';
    case CustomerReceipt = 'CUSTOMER_RECEIPT';
    case SupplierPayment = 'SUPPLIER_PAYMENT';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
