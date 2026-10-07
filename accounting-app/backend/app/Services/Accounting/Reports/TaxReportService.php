<?php

namespace App\Services\Accounting\Reports;

use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\CreditDebitNoteLine;
use App\Models\PurchaseBillLine;
use App\Models\SalesInvoiceLine;
use App\Models\Tax;
use App\Support\Money;
use App\Support\Rate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the company collected and recovered as tax over a period.
 *
 * Read-only and derived from documents that reached the ledger. Nothing here
 * recalculates a tax, re-reads a rate history, or consults the tax configuration
 * for anything except a name to print beside a figure: a report that recomputed
 * would disagree with the invoices it is reporting on as soon as a rate changed,
 * and a report is supposed to explain the books, not restate them.
 *
 * THE FIGURES COME FROM THE SNAPSHOT
 *
 * Every amount is read from the line's own `tax_rate` and `tax_amount` columns -
 * the values the document was calculated with and posted - not from the tax's
 * current configuration. The tax's *identity* is the only thing joined in, for
 * the code and name to print. A tax since deleted still reports, because the
 * figures are the line's own and the name is a label.
 *
 * WHY ATTRIBUTION IS INCOMPLETE, AND WHY THE REPORT SAYS SO
 *
 * `tax_id` is nullable on both line tables, and two ordinary cases leave it null:
 * a line that charged a hand-entered percentage rather than a configured tax, and
 * a line charging several taxes at once (one column holds one id).
 *
 * The tempting response is to drop those amounts, which would make every tax's row
 * look right. It would also make the report wrong in the way that matters most: the
 * rows would no longer foot to the ledger, and a tax return that did not reconcile
 * to the books is a return nobody can sign. So unattributed amounts are reported
 * explicitly, as their own row, and the totals include them. A reader can see
 * exactly how much of the period's tax the configuration could not explain.
 *
 * NOT A FILING
 *
 * This reports what the ledger says a company charged and recovered. It does not
 * reconcile to a tax authority's return, does not net off anything the ledger does
 * not contain, and applies no jurisdiction's rules. The brief's jurisdiction
 * concepts are not modelled anywhere in this application; inventing them here
 * would produce a document that looks like a return and is not one.
 */
class TaxReportService
{
    /**
     * Tax collected, tax recovered, and the difference, for a period.
     *
     * @return array{
     *     period: array{from: string|null, to: string|null},
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    public function summary(Company $company, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = $this->aggregate($company, $from, $to);

        $outputCollected = Money::zero();
        $inputRecovered = Money::zero();
        $unattributed = Money::zero();
        $salesTaxable = Money::zero();
        $purchaseTaxable = Money::zero();

        foreach ($rows as $row) {
            $outputCollected = $outputCollected->plus($row['output_tax']);
            $inputRecovered = $inputRecovered->plus($row['input_tax']);
            $unattributed = $unattributed->plus($row['unattributed_tax']);
            $salesTaxable = $salesTaxable->plus($row['sales_taxable']);
            $purchaseTaxable = $purchaseTaxable->plus($row['purchase_taxable']);
        }

        /*
         * Output minus input, which is what the company owes (or, if negative, has
         * overpaid and is owed back). Not called a "balance due" anywhere in the
         * output because the sign does not by itself say which of those it is -
         * only the magnitude and the jurisdiction would.
         */
        $net = $outputCollected->minus($inputRecovered);

        return [
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'rows' => $rows->map(fn (array $row) => $this->renderRow($row))->values()->all(),
            'totals' => [
                'sales_taxable' => $salesTaxable->toDatabase(),
                'output_tax' => $outputCollected->toDatabase(),
                'purchase_taxable' => $purchaseTaxable->toDatabase(),
                'input_tax' => $inputRecovered->toDatabase(),
                'net_tax' => $net->toDatabase(),
                'unattributed_tax' => $unattributed->toDatabase(),
            ],
        ];
    }

    /**
     * The same figures, one row per tax.
     *
     * @return array{
     *     period: array{from: string|null, to: string|null},
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    public function byTax(Company $company, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = $this->aggregate($company, $from, $to);

        $outputCollected = Money::zero();
        $inputRecovered = Money::zero();
        $unattributed = Money::zero();
        $salesTaxable = Money::zero();
        $purchaseTaxable = Money::zero();

        foreach ($rows as $row) {
            $outputCollected = $outputCollected->plus($row['output_tax']);
            $inputRecovered = $inputRecovered->plus($row['input_tax']);
            $unattributed = $unattributed->plus($row['unattributed_tax']);
            $salesTaxable = $salesTaxable->plus($row['sales_taxable']);
            $purchaseTaxable = $purchaseTaxable->plus($row['purchase_taxable']);
        }

        return [
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'base_currency' => $this->baseCurrency($company),
            'rows' => $rows->map(fn (array $row) => $this->renderRow($row))->values()->all(),
            'totals' => [
                'sales_taxable' => $salesTaxable->toDatabase(),
                'output_tax' => $outputCollected->toDatabase(),
                'purchase_taxable' => $purchaseTaxable->toDatabase(),
                'input_tax' => $inputRecovered->toDatabase(),
                'net_tax' => $outputCollected->minus($inputRecovered)->toDatabase(),
                'unattributed_tax' => $unattributed->toDatabase(),
            ],
        ];
    }

    /**
     * The same figures, one row per tax.
     *
     * @return array{
     *     period: array{from: string|null, to: string|null},
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    private function aggregate(Company $company, ?Carbon $from, ?Carbon $to): Collection
    {
        $rows = [];

        foreach ($this->salesLines($company, $from, $to) as $line) {
            $key = $line->tax_id ?? 'unattributed';

            $rows[$key] ??= $this->emptyRow($line->tax_id);

            /*
             * The taxable base is the line total less its tax, rather than
             * quantity x price, because the line total is what the document
             * actually charged and the difference is the discount and any other
             * adjustment already applied. Deriving the base from the stored total
             * is what makes the report's base agree with the document's own figures.
             *
             * Both legs are converted at the document's STORED snapshot rate (Phase
             * 14 §21.3): the ledger is a base-currency record, so a multi-currency
             * period can only be totalled in one currency, and that one currency is
             * the company's own. A base-currency document has no stored rate, so the
             * figures pass through untouched.
             */
            $tax = $line->baseTaxAmount();
            $taxable = $this->toSnapshotBase(
                Money::of($line->line_total)->minus(Money::of($line->tax_amount)),
                $line->document_rate,
            );

            if ($line->tax_id === null) {
                $rows[$key]['unattributed_tax'] = $rows[$key]['unattributed_tax']->plus($tax);
                $rows[$key]['output_tax'] = $rows[$key]['output_tax']->plus($tax);

                /*
                 * The base is reported even with no tax named, so a reader can still
                 * see the tax as a proportion of what it was charged on. Reporting
                 * the money without the base would leave a figure with nothing to
                 * check it against.
                 */
                $rows[$key]['sales_taxable'] = $rows[$key]['sales_taxable']->plus($taxable);

                continue;
            }

            $rows[$key]['sales_taxable'] = $rows[$key]['sales_taxable']->plus($taxable);
            $rows[$key]['output_tax'] = $rows[$key]['output_tax']->plus($tax);
        }

        /*
         * PHASE 11. Posted credit and debit notes contribute to the same totals,
         * with their sign.
         *
         * They must, or the report would overstate what the company collected and
         * recovered: an invoice worth 1000 carrying 20% tax reports 200 of output
         * tax, and a credit note for 300 of it reverses 60 of both the taxable base
         * and the tax - so omitting the note would leave the return undeclared and
         * the amount due to the authority overstated by exactly that 60.
         *
* A CREDIT note subtracts from every figure it touches and a DEBIT note
         * adds. Both live in one loop because the per-line work is otherwise
         * identical to the invoice loops - the taxable base is line_total less
         * tax_amount for the same reason it is there - and only the sign, the tax
         * side and the base row differ. See addNoteLine().
         *
         * Nothing here is clamped. A period in which credits exceeded debits
         * legitimately produces a negative output_tax, and that is the correct
         * figure - a net refund position. Clamping it to zero would understate the
         * refund owed, which is the one number in this report that must never be
         * reported smaller than it is.
         */
        foreach ($this->postedNoteLines($company, $from, $to) as $line) {
            $this->addNoteLine($rows, $line);
        }

        foreach ($this->purchaseLines($company, $from, $to) as $line) {
            $key = $line->tax_id ?? 'unattributed';

            $rows[$key] ??= $this->emptyRow($line->tax_id);

            $tax = $line->baseTaxAmount();
            $taxable = $this->toSnapshotBase(
                Money::of($line->line_total)->minus(Money::of($line->tax_amount)),
                $line->document_rate,
            );

            if ($line->tax_id === null) {
                $rows[$key]['unattributed_tax'] = $rows[$key]['unattributed_tax']->plus($tax);
                $rows[$key]['input_tax'] = $rows[$key]['input_tax']->plus($tax);
                $rows[$key]['purchase_taxable'] = $rows[$key]['purchase_taxable']->plus($taxable);

                continue;
            }

            $rows[$key]['purchase_taxable'] = $rows[$key]['purchase_taxable']->plus($taxable);
            $rows[$key]['input_tax'] = $rows[$key]['input_tax']->plus($tax);
        }

        return $this->withTaxIdentity($rows);
    }

    /**
     * @return Collection<int, SalesInvoiceLine>
     */
    private function salesLines(Company $company, ?Carbon $from, ?Carbon $to): Collection
    {
        return SalesInvoiceLine::query()
            ->select('sales_invoice_lines.*')
            ->addSelect('sales_invoices.exchange_rate as document_rate')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->where('sales_invoices.company_id', $company->getKey())
            ->where('sales_invoices.status', TransactionStatus::Posted)
            ->when($from, fn ($q) => $q->where('sales_invoices.invoice_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('sales_invoices.invoice_date', '<=', $to->toDateString()))
            ->get();
    }

    /**
     * @return Collection<int, PurchaseBillLine>
     */
    private function purchaseLines(Company $company, ?Carbon $from, ?Carbon $to): Collection
    {
        return PurchaseBillLine::query()
            ->select('purchase_bill_lines.*')
            ->addSelect('purchase_bills.exchange_rate as document_rate')
            ->join('purchase_bills', 'purchase_bills.id', '=', 'purchase_bill_lines.purchase_bill_id')
            ->where('purchase_bills.company_id', $company->getKey())
            ->where('purchase_bills.status', TransactionStatus::Posted)
            ->when($from, fn ($q) => $q->where('purchase_bills.bill_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('purchase_bills.bill_date', '<=', $to->toDateString()))
            ->get();
    }

    /**
     * Posted note lines of every type in the window, with their note attached.
     *
     * ONE query for all four types. The loops above need the note attached for two
     * reasons - the tax side and the sign - and both come from the note, so
     * splitting the query by type would only multiply the queries without changing
     * what any loop reads.
     *
     * The window is the note's own note_date and not the date of the document it
     * adjusts: a credit note raised in March for a February invoice is a March
     * transaction for tax purposes, and grouping it into February would restate a
     * period that has already been reported.
     *
     * @return Collection<int, CreditDebitNoteLine>
     */
    private function postedNoteLines(Company $company, ?Carbon $from, ?Carbon $to): Collection
    {
        return CreditDebitNoteLine::query()
            ->select('credit_debit_note_lines.*')
            ->addSelect('credit_debit_notes.exchange_rate as document_rate')
            ->with('note')
            ->join('credit_debit_notes', 'credit_debit_notes.id', '=', 'credit_debit_note_lines.credit_debit_note_id')
            ->where('credit_debit_notes.company_id', $company->getKey())
            ->where('credit_debit_notes.status', TransactionStatus::Posted->value)
            ->when($from, fn ($q) => $q->where('credit_debit_notes.note_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('credit_debit_notes.note_date', '<=', $to->toDateString()))
            ->get();
    }

    /**
     * Fold one note line into the aggregate, on the side its note reverses and with
     * the sign its note implies.
     *
     * Three decisions, all read from the note rather than from the line:
     *
     *  - WHICH SIDE. A sales note's tax is output tax and a purchase note's is
     *    input tax, whatever direction the note moves.
     *  - WHICH SIGN. A credit note subtracts from every figure it touches; a debit
     *    note adds. A credit note's line and a debit note's line are the same shape,
     *    so the line alone cannot say which it is.
     *  - WHICH BASE. line_total less tax_amount, for exactly the same reason as on
     *    an invoice: the base must be what was actually charged, net of discount.
     *    Both figures are the note's own snapshot, so they convert - see
     *    toSnapshotBase() - exactly as the invoice loops convert theirs.
     *
     * The row is passed by reference because this is an accumulator over many lines
     * and returning a new array per line would mean copying the whole table once
     * per line - which for a tax period with thousands of note lines is the
     * difference between one pass and one pass per line.
     *
     * @param  array<int|string, array<string, Money|int|null>>  $rows
     */
    private function addNoteLine(array &$rows, CreditDebitNoteLine $line): void
    {
        $note = $line->note;

        $key = $line->tax_id ?? 'unattributed';

        $rows[$key] ??= $this->emptyRow($line->tax_id);

        $add = ! $note->note_type->isCredit();

        $sales = $note->note_type->isSales();

        $tax = $line->baseTaxAmount();
        $taxable = $this->toSnapshotBase(
            Money::of($line->line_total)->minus(Money::of($line->tax_amount)),
            $line->document_rate,
        );

        /*
         * Unattributed tax is accumulated separately from the side's own tax
         * column, as it already is for invoices, so a reader can see how much of a
         * period's tax carries no configured tax behind it.
         */
        if ($line->tax_id === null) {
            $rows[$key]['unattributed_tax'] = $this->accumulate($rows[$key]['unattributed_tax'], $tax, $add);
        }

        $rows[$key][$sales ? 'sales_taxable' : 'purchase_taxable'] = $this->accumulate(
            $rows[$key][$sales ? 'sales_taxable' : 'purchase_taxable'],
            $taxable,
            $add
        );

        $rows[$key][$sales ? 'output_tax' : 'input_tax'] = $this->accumulate(
            $rows[$key][$sales ? 'output_tax' : 'input_tax'],
            $tax,
            $add
        );
    }

    /**
     * Add or subtract one term, exactly.
     *
     * A sign is never turned into a negative Money and added - that is how a
     * rounding error gets introduced into a tax figure - so the two cases are
     * separate calls on Money rather than a multiplication.
     */
    private function accumulate(Money $current, Money $term, bool $add): Money
    {
        return $add ? $current->plus($term) : $current->minus($term);
    }

    /**
     * @return array<string, Money|int|null>
     */
    private function emptyRow(?int $taxId): array
    {
        return [
            'tax_id' => $taxId,
            'tax_code' => null,
            'tax_name' => null,
            'tax_type' => null,
            'sales_taxable' => Money::zero(),
            'output_tax' => Money::zero(),
            'purchase_taxable' => Money::zero(),
            'input_tax' => Money::zero(),
            'unattributed_tax' => Money::zero(),
        ];
    }

    /**
     * Attach each row's code, name and type from the tax configuration.
     *
     * A tax that has since been deleted leaves its row intact with a null identity:
     * the amounts are the line's own and are still owed, and a report that dropped
     * them would understate what the company collected.
     *
     * @param  array<int|string, array<string, Money|int|null>>  $rows
     * @return Collection<int|string, array<string, Money|int|null>>
     */
    private function withTaxIdentity(array $rows): Collection
    {
        $ids = collect($rows)
            ->map(fn (array $row) => $row['tax_id'])
            ->filter()
            ->values();

        $taxes = $ids->isEmpty()
            ? collect()
            : Tax::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($rows as $key => $row) {
            $tax = $taxes->get($row['tax_id']);

            $rows[$key]['tax_code'] = $tax?->code;
            $rows[$key]['tax_name'] = $tax?->name;
            $rows[$key]['tax_type'] = $tax?->tax_type?->value;
        }

        return collect($rows);
    }

    /**
     * The base-currency disclosure block (Phase 14 §21.1).
     *
     * The tax totals are denominated in this currency. A tax figure is a money
     * fact and cannot be meaningfully combined across currencies - the whole
     * reason base_tax_amount is stored rather than derived - so a client that
     * knows which currency the totals are in cannot silently add a foreign row to
     * a base one. See JournalReportService::baseCurrency() for the shape.
     *
     * @return array{code: string|null, name: string|null, symbol: string|null, decimals: int|null}
     */
    private function baseCurrency(Company $company): array
    {
        $currency = $company->currency;

        return [
            'code' => $currency?->code,
            'name' => $currency?->name,
            'symbol' => $currency?->symbol,
            'decimals' => $currency?->decimal_precision,
        ];
    }

    /**
     * Carry an amount to the company's base currency at the document's snapshot.
     *
     * The rate passed in is the document's STORED exchange_rate (Phase 14 §21.3),
     * never today's. A base-currency document has no stored rate, and no amount is
     * converted when there is none - the figure is already base. This is the same
     * rule the settlement path uses, so a retired rate can never restate what a
     * period of tax was worth.
     */
    private function toSnapshotBase(Money $amount, mixed $rate): Money
    {
        return $rate === null
            ? $amount
            : Rate::of($rate)->applyTo($amount);
    }

    /**
     * Money to exact strings for the response.
     *
     * @param  array<string, Money|int|null>  $row
     * @return array<string, mixed>
     */
    private function renderRow(array $row): array
    {
        return [
            'tax_id' => $row['tax_id'],
            'tax_code' => $row['tax_code'],
            'tax_name' => $row['tax_name'],
            'tax_type' => $row['tax_type'],
            'sales_taxable' => $row['sales_taxable']->toDatabase(),
            'output_tax' => $row['output_tax']->toDatabase(),
            'purchase_taxable' => $row['purchase_taxable']->toDatabase(),
            'input_tax' => $row['input_tax']->toDatabase(),
            'net_tax' => $row['output_tax']->minus($row['input_tax'])->toDatabase(),
            'unattributed_tax' => $row['unattributed_tax']->toDatabase(),
        ];
    }
}
