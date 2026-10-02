<?php

namespace App\Enums;

/**
 * Which side of a transaction a tax applies to.
 *
 * Two values, not three, plus a combined one. The distinction that matters for
 * accounting is not the country's name for the tax but which side of the entry it
 * lands on:
 *
 *   OUTPUT  collected from a customer, credited to a liability
 *   INPUT   recovered from a supplier, debited to an asset
 *
 * Both posting services already speak in exactly these terms - Phase 5 credits
 * `tax_account_id` (a LIABILITY) on an invoice and debits the same column on a
 * bill after checking it is an ASSET - so naming the enum after them means the
 * tax configuration and the accounting entry describe the same thing with the
 * same word.
 *
 * Deliberately NOT modelled: any country's specific tax names. GST, VAT, CGST,
 * SGST, IGST and US sales tax are all the same two concepts with different
 * labels and, in some jurisdictions, more than one of them charged on the same
 * sale. A company in one of those jurisdictions configures two OUTPUT taxes and
 * the engine adds them, which is why BOTH and multiple components exist. What
 * the country calls the tax belongs in `Tax::name`, where it is a label and not
 * a branch in the arithmetic.
 */
enum TaxType: string
{
    case Output = 'OUTPUT';
    case Input = 'INPUT';
    case Both = 'BOTH';

    /**
     * May this tax be charged on a sales document?
     */
    public function appliesToSales(): bool
    {
        return $this === self::Output || $this === self::Both;
    }

    /**
     * May this tax be recovered on a purchase document?
     */
    public function appliesToPurchase(): bool
    {
        return $this === self::Input || $this === self::Both;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
