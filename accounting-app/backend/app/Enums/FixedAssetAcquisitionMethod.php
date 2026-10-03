<?php

namespace App\Enums;

/**
 * What the capitalisation entry credits.
 *
 * The brief gives the two entries an asset can produce (§11):
 *
 *   CASH             Dr Fixed Asset / Cr Cash or Bank
 *   SUPPLIER_CREDIT  Dr Fixed Asset / Cr Accounts Payable
 *
 * and this enum exists so the *type* of account the credit side must be is a
 * server-side decision derived from a method the user selected, rather than a
 * second request field a client could disagree with. The account id is one column;
 * what it is allowed to be is decided by which of these the asset carries. A
 * client that sent both `acquisition_method: CASH` and a liability account is
 * refused, because TransactionAccountResolver::cashBank() is asking whether the
 * account is classified as cash or bank - a question about the account, not a
 * preference the user may express here.
 *
 * SUPPLIER_CREDIT is NOT a purchase bill.
 *
 * It records that the company now owes a supplier for this asset. It does not
 * create a supplier, does not raise a document, and does not track a due date -
 * clearing it is done with Phase 5's existing supplier payment flow, which
 * allocates against a purchase bill. That is a known and documented gap rather
 * than an oversight: making capitalisation raise a real bill would require a
 * fixed-asset line type on purchase_bills, a change to a working module that buys
 * no accounting the entry does not already produce. See PHASE_12_REPORT.md.
 */
enum FixedAssetAcquisitionMethod: string
{
    case Cash = 'CASH';
    case SupplierCredit = 'SUPPLIER_CREDIT';

    /**
     * Does the credit side have to be an account classified as cash or bank?
     *
     * True only for a cash purchase. This is the single question the rest of the
     * capitalisation path asks before resolving the account, so the two branches
     * cannot drift into disagreeing about which account type is acceptable.
     */
    public function requiresCashBankAccount(): bool
    {
        return $this === self::Cash;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
