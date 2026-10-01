<?php

namespace App\Services\Accounting;

use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Server-side arithmetic for transactional document lines.
 *
 * One calculator for invoices and bills. The two documents differ in a field
 * name (unit_price vs unit_cost) and in which side of the entry they produce,
 * not in how a line's money is derived, and a second implementation would be a
 * second set of rounding decisions waiting to drift from the first.
 *
 * The calculation, per line:
 *
 *   gross   = quantity x unit price
 *   net     = gross - discount          (discount is an absolute amount)
 *   tax     = net x tax_rate / 100
 *   total   = net + tax
 *
 * and per document:
 *
 *   subtotal       = sum of gross
 *   discount_total = sum of discounts
 *   tax_total      = sum of tax
 *   grand_total    = subtotal - discount_total + tax_total
 *
 * Two choices in there are worth stating because they are not the only possible
 * ones:
 *
 *  1. `discount` is an absolute amount, not a rate. A percentage discount would
 *     have to define its base (gross or net-of-something), and the column is
 *     DECIMAL(20,4) alongside unit price rather than DECIMAL(6,4) alongside
 *     tax_rate, which is the schema saying it holds money.
 *
 *  2. Tax is charged on the discounted amount, not the gross. A tax authority
 *     assesses tax on what the customer pays. The alternative - tax on gross,
 *     with the discount treated as a separate reduction - is a real convention
 *     too, and it is reachable by entering a zero rate and a negative revenue
 *     adjustment line, so nothing is lost. But charging tax on the full price
 *     and then also giving the discount away would let a client construct a
 *     document that collects more tax than the sale is worth.
 *
 * Every value returned here is derived; none of it is ever accepted from a
 * client. The recalculation runs inside the same transaction that writes the
 * lines, so stored totals cannot disagree with the lines they summarise.
 */
class DocumentCalculator
{
    /**
     * Calculate one line's money and the document totals it contributes to.
     *
     * @param  array<string, mixed>  $line
     * @return array{
     *     line_number: int,
     *     quantity: string,
     *     unit_price: string,
     *     discount: string,
     *     tax_rate: string,
     *     tax_amount: string,
     *     line_total: string,
     *     gross: string
     * }
     *
     * @throws ValidationException when the line's own inputs are inconsistent
     */
    public function calculateLine(int $lineNumber, array $line, string $priceField = 'unit_price'): array
    {
        $quantity = $this->amount($line['quantity'] ?? 1, "lines.{$lineNumber}.quantity");
        $unitPrice = $this->amount($line[$priceField] ?? 0, "lines.{$lineNumber}.{$priceField}");
        $discount = $this->amount($line['discount'] ?? 0, "lines.{$lineNumber}.discount");
        $taxRate = $this->amount($line['tax_rate'] ?? 0, "lines.{$lineNumber}.tax_rate");

        if (! $quantity->isPositive()) {
            throw ValidationException::withMessages([
                "lines.{$lineNumber}.quantity" => 'A line quantity must be greater than zero.',
            ]);
        }

        if ($unitPrice->isNegative()) {
            throw ValidationException::withMessages([
                "lines.{$lineNumber}.{$priceField}" => 'A unit price cannot be negative.',
            ]);
        }

        if ($discount->isNegative()) {
            throw ValidationException::withMessages([
                "lines.{$lineNumber}.discount" => 'A discount cannot be negative.',
            ]);
        }

        if ($taxRate->isNegative()) {
            throw ValidationException::withMessages([
                "lines.{$lineNumber}.tax_rate" => 'A tax rate cannot be negative.',
            ]);
        }

        $gross = $quantity->times($unitPrice);

        /*
         * A discount larger than the line is refused rather than allowed to make
         * the line negative. A negative revenue line is a return, not a discount,
         * and admitting one here would mean a "sale" whose journal credits
         * accounts receivable - the opposite of what the document says it is.
         * Sales returns are recorded as their own contra-revenue document.
         */
        if ($discount->greaterThan($gross)) {
            throw ValidationException::withMessages([
                "lines.{$lineNumber}.discount" => sprintf(
                    'The discount of %s cannot exceed the line amount of %s.',
                    $discount,
                    $gross
                ),
            ]);
        }

        $net = $gross->minus($discount);
        $tax = $net->percentageOf($taxRate);
        $lineTotal = $net->plus($tax);

        return [
            'line_number' => $lineNumber,
            'quantity' => $quantity->toDatabase(),
            'unit_price' => $unitPrice->toDatabase(),
            'discount' => $discount->toDatabase(),
            'tax_rate' => $taxRate->toDatabase(),
            'tax_amount' => $tax->toDatabase(),
            'line_total' => $lineTotal->toDatabase(),
            'gross' => $gross->toDatabase(),
        ];
    }

    /**
     * Calculate every line and the document totals.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{
     *     lines: array<int, array<string, string>>,
     *     subtotal: string,
     *     discount_total: string,
     *     tax_total: string,
     *     grand_total: string
     * }
     *
     * @throws ValidationException
     */
    public function calculateDocument(array $lines, string $priceField = 'unit_price'): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A document must have at least one line.',
            ]);
        }

        $subtotal = Money::zero();
        $discountTotal = Money::zero();
        $taxTotal = Money::zero();

        $calculated = [];

        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages([
                    "lines.{$index}" => 'Each line must be an object.',
                ]);
            }

            $result = $this->calculateLine($index + 1, $line, $priceField);

            $subtotal = $subtotal->plus(Money::of($result['gross']));
            $discountTotal = $discountTotal->plus(Money::of($result['discount']));
            $taxTotal = $taxTotal->plus(Money::of($result['tax_amount']));

            $calculated[] = $result;
        }

        $grandTotal = $subtotal->minus($discountTotal)->plus($taxTotal);

        return [
            'lines' => $calculated,
            'subtotal' => $subtotal->toDatabase(),
            'discount_total' => $discountTotal->toDatabase(),
            'tax_total' => $taxTotal->toDatabase(),
            'grand_total' => $grandTotal->toDatabase(),
        ];
    }

    /**
     * The tax account a document must supply, given its computed tax total.
     *
     * A non-zero tax total with no tax account would leave the liability with
     * nowhere to go: the journal would either not balance or would quietly drop
     * the tax. The check is a validation error naming the field rather than a
     * database constraint, because the rule is conditional and the message can
     * explain why.
     *
     * @throws ValidationException
     */
    public function assertTaxAccountPresent(Money $taxTotal, ?int $taxAccountId, string $field = 'tax_account_id'): void
    {
        if ($taxTotal->isPositive() && $taxAccountId === null) {
            throw ValidationException::withMessages([
                $field => 'This document charges tax, so a tax account is required to record where that tax is owed.',
            ]);
        }
    }

    /**
     * Parse a submitted amount with the same tolerance the request layer allows.
     *
     * ofTolerant rather than of: a client sending 0.00001 for a quantity should
     * be rounded the way the decimal tolerance config documents, not rejected
     * for precision the ledger will not keep anyway.
     *
     * @throws ValidationException
     */
    private function amount(mixed $value, string $field): Money
    {
        try {
            return Money::ofTolerant($value ?? 0);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                $field => 'This value is not a valid number.',
            ]);
        }
    }
}
