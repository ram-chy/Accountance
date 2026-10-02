<?php

namespace App\Services\Accounting\Reports;

use App\Enums\TransactionStatus;
use App\Models\Company;
use App\Models\PurchaseBillLine;
use App\Models\SalesInvoiceLine;
use App\Models\Tax;
use App\Support\Money;
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

        foreach ($rows as $row) {
            $outputCollected = $outputCollected->plus($row['output_tax']);
            $inputRecovered = $inputRecovered->plus($row['input_tax']);
            $unattributed = $unattributed->plus($row['unattributed_tax']);
        }

        return [
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'rows' => $rows->map(fn (array $row) => $this->renderRow($row))->values()->all(),
            'totals' => [
                'output_tax' => $outputCollected->toDatabase(),
                'input_tax' => $inputRecovered->toDatabase(),
                'net_tax' => $outputCollected->minus($inputRecovered)->toDatabase(),
                'unattributed_tax' => $unattributed->toDatabase(),
            ],
        ];
    }

    /**
     * Accumulate every posted line's figures, keyed by tax.
     *
     * Keyed by tax_id with null as its own key, which is what keeps unattributed
     * amounts in the totals instead of quietly dropping them. One query per
     * document type rather than per tax: a company with thirty taxes and a thousand
     * documents should not issue a thousand queries to produce thirty rows.
     *
     * @return Collection<int|string, array<string, Money|int|null>>
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
             */
            $taxable = Money::of($line->line_total)->minus(Money::of($line->tax_amount));

            if ($line->tax_id === null) {
                $rows[$key]['unattributed_tax'] = $rows[$key]['unattributed_tax']->plus(Money::of($line->tax_amount));
                $rows[$key]['output_tax'] = $rows[$key]['output_tax']->plus(Money::of($line->tax_amount));

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
            $rows[$key]['output_tax'] = $rows[$key]['output_tax']->plus(Money::of($line->tax_amount));
        }

        foreach ($this->purchaseLines($company, $from, $to) as $line) {
            $key = $line->tax_id ?? 'unattributed';

            $rows[$key] ??= $this->emptyRow($line->tax_id);

            $taxable = Money::of($line->line_total)->minus(Money::of($line->tax_amount));

            if ($line->tax_id === null) {
                $rows[$key]['unattributed_tax'] = $rows[$key]['unattributed_tax']->plus(Money::of($line->tax_amount));
                $rows[$key]['input_tax'] = $rows[$key]['input_tax']->plus(Money::of($line->tax_amount));
                $rows[$key]['purchase_taxable'] = $rows[$key]['purchase_taxable']->plus($taxable);

                continue;
            }

            $rows[$key]['purchase_taxable'] = $rows[$key]['purchase_taxable']->plus($taxable);
            $rows[$key]['input_tax'] = $rows[$key]['input_tax']->plus(Money::of($line->tax_amount));
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
            ->join('purchase_bills', 'purchase_bills.id', '=', 'purchase_bill_lines.purchase_bill_id')
            ->where('purchase_bills.company_id', $company->getKey())
            ->where('purchase_bills.status', TransactionStatus::Posted)
            ->when($from, fn ($q) => $q->where('purchase_bills.bill_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('purchase_bills.bill_date', '<=', $to->toDateString()))
            ->get();
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
