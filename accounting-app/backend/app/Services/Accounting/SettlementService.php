<?php

namespace App\Services\Accounting;

use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\CustomerReceiptAllocation;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierPaymentAllocation;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Derives settlement figures and payment status from allocation rows.
 *
 * The Phase 5 brief lists `paid_total` and `balance_due` as suggested columns.
 * They are not stored, and the reason is the one the phase 4 report already
 * applied to the ledger: a second copy of a figure is a second source of truth,
 * and a stored `paid_total` that disagrees with the allocations it claims to
 * summarise has no correct resolution. The allocations are the fact; everything
 * here is a sum over them.
 *
 * This is also why there is no incremental counter. Reading a balance means
 * summing the allocations for one document, which is a bounded, indexed
 * aggregate over a table that only grows by one row per payment line. A cached
 * running total would only be a performance shortcut, and it would be a shortcut
 * that can be wrong - a counter must be decremented when a receipt is removed,
 * and a missed decrement is a permanent wrong balance with no way to notice.
 *
 * Status follows from the same numbers, with one rule worth stating: a draft
 * stays a draft no matter what is allocated to it, because a draft has no
 * accounting entry yet. Only a posted document can be settled. Without that rule
 * a draft invoice with allocations would report PARTIALLY_PAID while
 * contributing nothing to the ledger, and an outstanding-AR report would then
 * disagree with the ledger by exactly the draft's balance.
 */
class SettlementService
{
    /**
     * Total allocated against an invoice, counting posted receipts only.
     *
     * Draft receipts are excluded because they have not moved money. This is the
     * same reasoning that makes draft journals invisible to LedgerService: the
     * allocation row exists, but the document it belongs to is not in the record.
     */
    public function allocatedToInvoice(int $invoiceId): Money
    {
        return Money::of((string) CustomerReceiptAllocation::query()
            ->join('customer_receipts', 'customer_receipts.id', '=', 'customer_receipt_allocations.customer_receipt_id')
            ->where('customer_receipt_allocations.sales_invoice_id', $invoiceId)
            ->where('customer_receipts.status', PaymentStatus::Posted->value)
            ->sum('customer_receipt_allocations.amount'));
    }

    /**
     * Total allocated against a bill, counting posted payments only.
     */
    public function allocatedToBill(int $billId): Money
    {
        return Money::of((string) SupplierPaymentAllocation::query()
            ->join('supplier_payments', 'supplier_payments.id', '=', 'supplier_payment_allocations.supplier_payment_id')
            ->where('supplier_payment_allocations.purchase_bill_id', $billId)
            ->where('supplier_payments.status', PaymentStatus::Posted->value)
            ->sum('supplier_payment_allocations.amount'));
    }

    /**
     * What a customer still owes across all of their posted invoices.
     *
     * One grouped query rather than a per-invoice sum: an aged receivables report
     * for a customer with 200 invoices would otherwise issue 200 queries, and the
     * number is a single aggregate over the same two tables either way.
     */
    public function customerReceivable(Customer $customer): Money
    {
        $invoices = SalesInvoice::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->get(['id', 'grand_total']);

        if ($invoices->isEmpty()) {
            return Money::zero();
        }

        $allocated = $this->allocatedByInvoiceIds($invoices->pluck('id')->all());

        $total = Money::zero();

        foreach ($invoices as $invoice) {
            $total = $total->plus(
                Money::of($invoice->grand_total)
                    ->minus($allocated->get($invoice->getKey(), Money::zero()))
            );
        }

        return $total;
    }

    /**
     * What a company still owes a supplier across all of their posted bills.
     */
    public function supplierPayable(Supplier $supplier): Money
    {
        $bills = PurchaseBill::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('status', '!=', TransactionStatus::Draft->value)
            ->get(['id', 'grand_total']);

        if ($bills->isEmpty()) {
            return Money::zero();
        }

        $allocated = $this->allocatedByBillIds($bills->pluck('id')->all());

        $total = Money::zero();

        foreach ($bills as $bill) {
            $total = $total->plus(
                Money::of($bill->grand_total)
                    ->minus($allocated->get($bill->getKey(), Money::zero()))
            );
        }

        return $total;
    }

    /**
     * The settlement figures for an invoice, for the API layer.
     *
     * @return array{paid_total: string, balance_due: string}
     */
    public function figuresFor(SalesInvoice $invoice): array
    {
        $allocated = $this->allocatedToInvoice($invoice->getKey());

        return $this->figures(Money::of($invoice->grand_total), $allocated);
    }

    /**
     * The settlement figures for a bill.
     *
     * @return array{paid_total: string, balance_due: string}
     */
    public function billFigures(PurchaseBill $bill): array
    {
        $allocated = $this->allocatedToBill($bill->getKey());

        return $this->figures(Money::of($bill->grand_total), $allocated);
    }

    /**
     * Settlement figures for many invoices at once, attached to each model.
     *
     * This exists because the per-document method above is the wrong shape for a
     * list endpoint. An index of 100 invoices calling figuresFor() 100 times
     * issues 100 aggregate queries; this issues one and attaches the results, so
     * the serialiser can read paid_total off each model without touching the
     * database again.
     *
     * @param  Collection<int, SalesInvoice>|iterable<int, SalesInvoice>  $invoices
     */
    public function attachFiguresForInvoices(iterable $invoices): void
    {
        $documents = collect($invoices)->values();

        if ($documents->isEmpty()) {
            return;
        }

        $allocations = $this->allocatedByInvoiceIds($documents->pluck('id')->all());

        foreach ($documents as $invoice) {
            $invoice->setSettlement(
                $this->figures(
                    Money::of($invoice->grand_total),
                    $allocations->get($invoice->getKey(), Money::zero())
                )
            );
        }
    }

    /**
     * Settlement figures for many bills at once, attached to each model.
     *
     * @param  Collection<int, PurchaseBill>|iterable<int, PurchaseBill>  $bills
     */
    public function attachFiguresForBills(iterable $bills): void
    {
        $documents = collect($bills)->values();

        if ($documents->isEmpty()) {
            return;
        }

        $allocations = $this->allocatedByBillIds($documents->pluck('id')->all());

        foreach ($documents as $bill) {
            $bill->setSettlement(
                $this->figures(
                    Money::of($bill->grand_total),
                    $allocations->get($bill->getKey(), Money::zero())
                )
            );
        }
    }

    /**
     * The status an invoice should be in, given its allocations.
     *
     * The spec (section 37) gives the three cases in terms of paid_total and
     * grand_total, and the comparisons here are exact decimal ones - `equals`
     * and `greaterThan` on Money, never float comparisons. Two receipts of 0.10
     * and 0.20 must settle a 0.30 invoice, and a float comparison of
     * 0.30000000000000004 against 0.3 would call that invoice PARTIALLY_PAID
     * forever.
     */
    public function statusForInvoice(SalesInvoice $invoice): TransactionStatus
    {
        if ($invoice->status->isDraft()) {
            return TransactionStatus::Draft;
        }

        return $this->statusFromFigures(
            Money::of($invoice->grand_total),
            $this->allocatedToInvoice($invoice->getKey())
        );
    }

    /**
     * The status a bill should be in, given its allocations.
     */
    public function statusForBill(PurchaseBill $bill): TransactionStatus
    {
        if ($bill->status->isDraft()) {
            return TransactionStatus::Draft;
        }

        return $this->statusFromFigures(
            Money::of($bill->grand_total),
            $this->allocatedToBill($bill->getKey())
        );
    }

    /**
     * Recompute and store an invoice's settlement status.
     *
     * Called with the invoice row locked, after a receipt has been posted
     * against it. The status column is the only place this lands; paid_total and
     * balance_due stay derived.
     */
    public function refreshInvoiceStatus(SalesInvoice $invoice): SalesInvoice
    {
        $status = $this->statusForInvoice($invoice);

        if ($invoice->status !== $status) {
            $invoice->forceFill(['status' => $status->value])->save();
        }

        return $invoice;
    }

    /**
     * Recompute and store a bill's settlement status.
     */
    public function refreshBillStatus(PurchaseBill $bill): PurchaseBill
    {
        $status = $this->statusForBill($bill);

        if ($bill->status !== $status) {
            $bill->forceFill(['status' => $status->value])->save();
        }

        return $bill;
    }

    /**
     * @return array{paid_total: string, balance_due: string}
     */
    private function figures(Money $grandTotal, Money $allocated): array
    {
        $balance = $grandTotal->minus($allocated);

        /*
         * Clamped at zero. Allocation validation prevents an invoice from being
         * over-allocated, but a report should still degrade to "nothing owing"
         * rather than print a negative balance, which a UI would render as
         * "-50.00" next to an amount owed.
         */
        if ($balance->isNegative()) {
            $balance = Money::zero();
        }

        return [
            'paid_total' => $allocated->toDatabase(),
            'balance_due' => $balance->toDatabase(),
        ];
    }

    /**
     * The status any document should be in, given its total and allocations.
     *
     * Public so Phase 6's receivables/payables reports can label each outstanding
     * document without a second copy of this rule. The reports already have the
     * grand total and the allocated figure from their batch query; recomputing
     * the status from raw numbers must agree with the stored status that the
     * posting path wrote, and sharing this method is how the two are kept in
     * step.
     */
    public function statusFromFigures(Money $grandTotal, Money $allocated): TransactionStatus
    {
        if ($allocated->isZero()) {
            return TransactionStatus::Posted;
        }

        /*
         * Over-allocation is rejected when the allocation is written, so this is
         * a backstop against data that predates a rule change rather than the
         * enforcement point. Reporting PAID is the honest reading of "this
         * document has no outstanding balance".
         */
        if ($allocated->greaterThan($grandTotal) || $allocated->equals($grandTotal)) {
            return TransactionStatus::Paid;
        }

        return TransactionStatus::PartiallyPaid;
    }

    /**
     * Allocations per invoice id for a set of invoices, in one query.
     *
     * @param  array<int, int>  $invoiceIds
     * @return Collection<int, Money>
     */
    private function allocatedByInvoiceIds(array $invoiceIds): Collection
    {
        return $this->summedAllocations(
            allocationTable: 'customer_receipt_allocations',
            documentColumn: 'customer_receipt_allocations.sales_invoice_id',
            paymentTable: 'customer_receipts',
            allocationPaymentColumn: 'customer_receipt_allocations.customer_receipt_id',
            documentIds: $invoiceIds,
        );
    }

    /**
     * Allocations per bill id for a set of bills, in one query.
     *
     * @param  array<int, int>  $billIds
     * @return Collection<int, Money>
     */
    private function allocatedByBillIds(array $billIds): Collection
    {
        return $this->summedAllocations(
            allocationTable: 'supplier_payment_allocations',
            documentColumn: 'supplier_payment_allocations.purchase_bill_id',
            paymentTable: 'supplier_payments',
            allocationPaymentColumn: 'supplier_payment_allocations.supplier_payment_id',
            documentIds: $billIds,
        );
    }

    /**
     * One grouped sum per document, over posted payments only.
     *
     * Raw DB rather than the Eloquent relations: this is a grouped aggregate, and
     * the group key has to be the raw document id. MySQL sums DECIMAL as DECIMAL,
     * so the strings coming back are exact; they are routed through Money only to
     * get canonical formatting before being handed to a caller.
     *
     * @param  array<int, int>  $documentIds
     * @return Collection<int, Money>
     */
    private function summedAllocations(
        string $allocationTable,
        string $documentColumn,
        string $paymentTable,
        string $allocationPaymentColumn,
        array $documentIds
    ): Collection {
        if ($documentIds === []) {
            return new Collection;
        }

        $rows = DB::table($allocationTable)
            ->join($paymentTable, $paymentTable.'.id', '=', $allocationPaymentColumn)
            ->whereIn($documentColumn, $documentIds)
            ->where($paymentTable.'.status', PaymentStatus::Posted->value)
            ->groupBy($documentColumn)
            ->selectRaw("{$documentColumn} as document_id, sum({$allocationTable}.amount) as total")
            ->pluck('total', 'document_id');

        return $rows->map(fn ($total) => Money::of((string) $total));
    }
}
