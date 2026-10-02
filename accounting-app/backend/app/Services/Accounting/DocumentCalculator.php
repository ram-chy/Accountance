<?php

namespace App\Services\Accounting;

use App\Enums\TaxCalculationBasis;
use App\Services\Accounting\Tax\TaxCalculationService;
use App\Services\Accounting\Tax\TaxComponentResult;
use App\Services\Accounting\Tax\TaxRuleResolver;
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
 *
 * TWO WAYS A LINE GETS ITS TAX
 *
 * Phase 5 gave a line one number: a hand-entered `tax_rate`, taxed on top of the
 * net. That path is untouched. A line may instead name configured taxes in
 * `tax_ids`, and then the rate is not read from the line at all - it is resolved
 * from the tax's effective history for the document's date, and the arithmetic is
 * done by TaxCalculationService. The distinction is not cosmetic: the same tax at
 * 10% charges a different amount depending on which day the document is dated, and
 * only the configured path can know that.
 *
 * CONFIGURED TAXES ON A DOCUMENT ARE EXCLUSIVE ONLY
 *
 * A tax whose basis is INCLUSIVE is refused on a document line rather than
 * supported. This is a real limit and it is a deliberate one:
 *
 * Phase 5's document algebra charges tax on top of the quoted amount. `line_total`
 * is net + tax, the document total is subtotal - discount + tax_total, and
 * SalesInvoicePostingService derives revenue as `line_total - tax_amount`. All
 * three say the same thing: tax is additional to the price on the line.
 *
 * An inclusive tax breaks all three at once. The quoted amount already contains
 * the tax, so subtotal would have to mean a different thing on that line than on
 * its neighbours, and the whole-document identity that lets a document's total be
 * recomputed from its lines would stop holding for a document mixing the two.
 * Supporting it properly means changing those invariants and the posting code
 * with them, which is a change to Phase 5 rather than an addition to Phase 10.
 *
 * So the engine's inclusive direction is available where it can be expressed
 * exactly - the calculation endpoint - and a document that asks for it is told why,
 * instead of being posted on a basis that does not foot.
 */
class DocumentCalculator
{
    public function __construct(
        private readonly TaxCalculationService $taxEngine,
        private readonly TaxRuleResolver $taxRules,
    ) {}

    /**
     * Calculate one line's money and the document totals it contributes to.
     *
     * @param  array<string, mixed>  $line
     * @param  DocumentTaxContext|null  $taxContext  null when the document does not resolve configured taxes
     * @return array{
     *     line_number: int,
     *     quantity: string,
     *     unit_price: string,
     *     discount: string,
     *     tax_rate: string,
     *     tax_amount: string,
     *     tax_id: int|null,
     *     line_total: string,
     *     gross: string
     * }
     *
     * @throws ValidationException when the line's own inputs are inconsistent
     */
    public function calculateLine(
        int $lineNumber,
        array $line,
        string $priceField = 'unit_price',
        ?DocumentTaxContext $taxContext = null,
    ): array {
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

        $tax = $this->taxOn($lineNumber, $line, $net, $taxRate, $taxContext);

        /*
         * `line_total` stays net + tax for both paths, which is the Phase 5
         * invariant the posting service relies on when it derives revenue as
         * line_total - tax_amount. Exclusive is the only basis a document accepts
         * (see the class docblock), so for a configured tax this is the same
         * figure the engine's gross is.
         */
        $taxAmount = $tax['tax'];
        $lineTotal = $net->plus($taxAmount);

        return [
            'line_number' => $lineNumber,
            'quantity' => $quantity->toDatabase(),
            'unit_price' => $unitPrice->toDatabase(),
            'discount' => $discount->toDatabase(),
            'tax_rate' => $tax['rate']->toDatabase(),
            'tax_amount' => $taxAmount->toDatabase(),
            'tax_id' => $tax['tax_id'],
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
    public function calculateDocument(
        array $lines,
        string $priceField = 'unit_price',
        ?DocumentTaxContext $taxContext = null,
    ): array {
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

            $result = $this->calculateLine($index + 1, $line, $priceField, $taxContext);

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
     * A line's tax, its rate, and the configured tax it should be snapshotted against.
     *
     * Two paths, chosen by whether the line names configured taxes:
     *
     *  - Named: the rate comes from the tax's effective history for the document's
     *    date, and the arithmetic comes from TaxCalculationService. The line's own
     *    `tax_rate` is not consulted, because a document that names a tax must be
     *    charged that tax's rate on its date, not whatever percentage was typed.
     *  - Not named: the hand-entered rate is taxed on the net, exactly as Phase 5
     *    did. A line may carry a `tax_rate` and name no taxes, and that document is
     *    unchanged by this phase.
     *
     * The returned `tax_id` is what the line snapshots so a report can group by tax.
     * It is set only when exactly one tax applies: the column is a single foreign
     * key, and picking one of several to store would attribute part of the amount to
     * a tax that never charged all of it. A multi-tax line stores null and is
     * reported as unattributed, which is the honest answer rather than a wrong one.
     *
     * @return array{tax: Money, rate: Money, tax_id: int|null}
     *
     * @throws ValidationException
     */
    private function taxOn(
        int $lineNumber,
        array $line,
        Money $net,
        Money $submittedRate,
        ?DocumentTaxContext $context,
    ): array {
        $ids = collect($line['tax_ids'] ?? [])->values();

        if ($context === null || $ids->isEmpty()) {
            return [
                'tax' => $net->percentageOf($submittedRate),
                'rate' => $submittedRate,
                'tax_id' => null,
            ];
        }

        $field = "lines.{$lineNumber}.tax_ids";

        $taxes = $this->taxRules->resolve($context->company, $context->output, $context->date, $ids);

        /*
         * Inclusive is refused here, before any arithmetic, rather than silently
         * treated as exclusive - see the class docblock for why a document cannot
         * carry it. Naming the field means the user is pointed at the part of their
         * request to change.
         */
        $inclusive = $taxes->first(fn ($tax) => $tax->calculation_basis === TaxCalculationBasis::Inclusive);

        if ($inclusive !== null) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'Tax [%s] is configured as INCLUSIVE, meaning its rate is already inside the amount '
                    .'being taxed. A document charges tax on top of its line amounts, so it cannot use an '
                    .'inclusive tax. Use the calculation endpoint to price a tax-inclusive figure, or '
                    .'configure this tax as EXCLUSIVE.',
                    $inclusive->code
                ),
            ]);
        }

        $result = $this->taxEngine->calculate($net, $taxes, $context->date, TaxCalculationBasis::Exclusive, $field);

        /*
         * The rate stored on the line is the sum of the component rates. For the
         * ordinary single-tax line that is the tax's own rate, exactly. For a line
         * carrying several, it is the only single number that summarises them without
         * inventing one - and it is a presentation figure only: `tax_amount` is
         * computed from the components and is what the document actually charges.
         */
        $rate = $result->components->reduce(
            fn (Money $carry, TaxComponentResult $component) => $carry->plus($component->rate),
            Money::zero()
        );

        return [
            'tax' => $result->totalTax,
            'rate' => $rate,
            'tax_id' => $taxes->count() === 1 ? $taxes->first()->getKey() : null,
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
