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
 * Phase 7 follows the same bar: CashBankTransaction is declared because
 * CashBankPostingService produces it, once per cash/bank transaction.
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

    /*
     * Phase 7 is that phase for cash and bank.
     *
     * One case rather than three, because there is one operational table
     * (cash_bank_transactions) and the direction lives in that table's
     * transaction_type column. Adding Deposit, Withdrawal and Transfer as three
     * cases would make source_id ambiguous - three sources against one id
     * column, with no way to tell which produced a given journal.
     *
     * This is consistent with the rule stated above: the value names the
     * document, and a deposit, a withdrawal and a transfer are three shapes of
     * one document.
     */
    case CashBankTransaction = 'CASH_BANK_TRANSACTION';

    /*
     * Phase 11 is that phase for credit and debit notes.
     *
     * ONE case rather than four, on the same reasoning Phase 7 used for cash/bank
     * transactions: there is one table (credit_debit_notes), one producer
     * (CreditDebitNotePostingService) and one source_id column. Four cases
     * (SALES_CREDIT_NOTE, SALES_DEBIT_NOTE, PURCHASE_CREDIT_NOTE,
     * PURCHASE_DEBIT_NOTE) would all point at credit_debit_notes.id, so nothing
     * would be gained and a reader would have to consult credit_debit_notes to
     * learn which of the four produced a given journal - which they can do,
     * because the note carries note_type on its own row.
     *
     * This follows the rule stated at the top of this file: the value names the
     * document. A credit note and a debit note are two shapes of one document,
     * exactly as a deposit and a transfer are two shapes of one cash/bank
     * transaction.
     */
    case CreditDebitNote = 'CREDIT_DEBIT_NOTE';

    /*
     | Phase 12 is that phase for fixed assets.
     |
     | THREE cases, not one - and this is the opposite decision to Phase 7 and
     | Phase 11, for exactly the reason those two made their single-case choice.
     | They collapsed several entry kinds onto one case because they shared ONE
     | table, so a single source_id column could not tell them apart without
     | opening that table. A fixed asset has three operational tables:
     |
     |   fixed_assets               - the capitalisation entry
     |   fixed_asset_depreciations  - one entry per depreciation period
     |   fixed_asset_disposals      - the disposal entry
     |
     | so source_id resolves against three different tables and the enum value is
     | the only thing that says which. Collapsing them into one FIXED_ASSET case
     | would make every fixed-asset journal ambiguous the moment it was read
     | without the asset, which is precisely the situation a source column exists
     | for.
     |
     | Each case still names a document rather than a direction of money, so the
     | rule at the top of this file holds: a depreciation entry is a document, and
     | so is a disposal.
     */
    case FixedAsset = 'FIXED_ASSET';
    case FixedAssetDepreciation = 'FIXED_ASSET_DEPRECIATION';
    case FixedAssetDisposal = 'FIXED_ASSET_DISPOSAL';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
