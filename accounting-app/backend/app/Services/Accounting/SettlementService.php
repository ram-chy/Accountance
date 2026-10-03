<?php

namespace App\Services\Accounting;

use App\Enums\NoteType;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Models\CreditDebitNote;
use App\Models\Customer;
use App\Models\CustomerReceiptAllocation;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierPaymentAllocation;
use App\Services\Accounting\Notes\CreditDebitNoteAdjustmentService;
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
 *
 * PHASE 11: THE BALANCE IS NET OF POSTED CREDIT AND DEBIT NOTES
 *
 * Everything above counted one thing: money received. A credit note is not money -
 * it is not a receipt and it allocates nothing - but it does reduce what the
 * counterparty owes, so leaving it out would leave the balance permanently
 * overstated by the amount credited, with no available way to correct it short of
 * a journal the application will not create.
 *
 * The sign convention is the adjustment service's, not this class's:
 *
 *     balance = grand_total - paid - (posted credits - posted debits)
 *
 * The adjustment arithmetic lives in CreditDebitNoteAdjustmentService and is
 * injected rather than reimplemented here, because the same figure is summed in
 * three other places (both model's withOutstandingBalance scopes, and the note
 * posting service) and a fourth copy of a sign convention is a fourth chance to
 * write it backwards.
 *
 * WHAT DOES NOT CHANGE: PAYMENT STATUS IS ABOUT MONEY, NOT NOTES
 *
 * statusFromFigures keeps its "allocated is zero" test first, so a document that
 * has been credited but never paid still reports POSTED rather than
 * PARTIALLY_PAID - there has been no part-payment, and reporting one would be a
 * false statement about cash received. Everything after that point is decided by
 * the note-adjusted balance, which is what keeps the pair self-consistent:
 *
 *     allocated > 0 and balance == 0  ->  PAID
 *     allocated > 0 and balance > 0   ->  PARTIALLY_PAID
 *     allocated == 0                 ->  POSTED
 *
 * An invoice paid in full and then credited reports PARTIALLY_PAID with a
 * balance_due, which is the truthful answer: the customer has paid everything
 * invoiced and is owed a credit. The alternative - leaving the status at PAID
 * while balance_due shows money outstanding - would be two keys on the same
 * object disagreeing about whether the document is settled, and every consumer of
 * them would have to know which one to believe.
 */
class SettlementService
{
    public function __construct(
        private readonly CreditDebitNoteAdjustmentService $adjustments,
    ) {}

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

        $ids = $invoices->pluck('id')->all();

        $allocated = $this->allocatedByInvoiceIds($ids);
        $netNotes = $this->netNotesByDocumentIds($ids, 'sales_invoice_id');

        $total = Money::zero();

        foreach ($invoices as $invoice) {
            $total = $total->plus(
                $this->balance(
                    Money::of($invoice->grand_total),
                    $allocated->get($invoice->getKey(), Money::zero()),
                    $netNotes->get($invoice->getKey(), Money::zero()),
                )
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

        $ids = $bills->pluck('id')->all();

        $allocated = $this->allocatedByBillIds($ids);
        $netNotes = $this->netNotesByDocumentIds($ids, 'purchase_bill_id');

        $total = Money::zero();

        foreach ($bills as $bill) {
            $total = $total->plus(
                $this->balance(
                    Money::of($bill->grand_total),
                    $allocated->get($bill->getKey(), Money::zero()),
                    $netNotes->get($bill->getKey(), Money::zero()),
                )
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
        return $this->figures(
            Money::of($invoice->grand_total),
            $this->allocatedToInvoice($invoice->getKey()),
            $this->adjustments->netAdjustmentForInvoice($invoice->getKey()),
        );
    }

    /**
     * The settlement figures for a bill.
     *
     * @return array{paid_total: string, balance_due: string}
     */
    public function billFigures(PurchaseBill $bill): array
    {
        return $this->figures(
            Money::of($bill->grand_total),
            $this->allocatedToBill($bill->getKey()),
            $this->adjustments->netAdjustmentForBill($bill->getKey()),
        );
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

        $ids = $documents->pluck('id')->all();

        $allocations = $this->allocatedByInvoiceIds($ids);
        $netNotes = $this->netNotesByDocumentIds($ids, 'sales_invoice_id');

        foreach ($documents as $invoice) {
            $invoice->setSettlement(
                $this->figures(
                    Money::of($invoice->grand_total),
                    $allocations->get($invoice->getKey(), Money::zero()),
                    $netNotes->get($invoice->getKey(), Money::zero()),
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

        $ids = $documents->pluck('id')->all();

        $allocations = $this->allocatedByBillIds($ids);
        $netNotes = $this->netNotesByDocumentIds($ids, 'purchase_bill_id');

        foreach ($documents as $bill) {
            $bill->setSettlement(
                $this->figures(
                    Money::of($bill->grand_total),
                    $allocations->get($bill->getKey(), Money::zero()),
                    $netNotes->get($bill->getKey(), Money::zero()),
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
            $this->allocatedToInvoice($invoice->getKey()),
            $this->adjustments->netAdjustmentForInvoice($invoice->getKey()),
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
            $this->allocatedToBill($bill->getKey()),
            $this->adjustments->netAdjustmentForBill($bill->getKey()),
        );
    }

    /**
     * Recompute and store an invoice's settlement status.
     *
     * Called with the invoice row locked, after a receipt has been posted
     * against it. The status column is the only place this lands; paid_total and
     * balance_due stay derived.
     *
     * A SETTLED DOCUMENT IS NOT UNSETTLED BY A LATER NOTE
     *
     * The note posting service calls this too, and the guard below is the reason
     * that is safe rather than destructive. An invoice marked PAID or
     * PARTIALLY_PAID has had money posted against it, and a credit note issued
     * afterwards is a conversation with the customer: they have paid 200.00 and now
     * are asking for 50.00 back, and the right answer is a refund, not a document
     * that quietly becomes unpaid and drags the receivables ageing as though the
     * customer had stopped paying.
     *
     * The credit itself is never in doubt - the journal is written, the balance_due
     * beside this status does fall to 50.00, and the receivables report excludes
     * the invoice because its balance is no longer positive. What is protected is
     * only the label, and the label is the one thing in this module that describes
     * what happened rather than what is currently outstanding. balance_due answers
     * the second question and is always derived fresh.
     *
     * The guard is the status column rather than the allocated amount because a
     * document that is fully allocated is by definition settled: if something were
     * to have de-allocated it, the status would have been rewritten already, and
     * reading the label is reading the committed history rather than re-deriving it.
     *
     * @see statusFromFigures for why a never-paid document reports POSTED.
     */
    public function refreshInvoiceStatus(SalesInvoice $invoice): SalesInvoice
    {
        $status = $this->statusForInvoice($invoice);

        if ($invoice->status->isSettled()) {
            return $invoice;
        }

        if ($invoice->status !== $status) {
            $invoice->forceFill(['status' => $status->value])->save();
        }

        return $invoice;
    }

    /**
     * Recompute and store a bill's settlement status.
     *
     * Settled bills are protected from being unset by a later note, exactly as
     * invoices are - see refreshInvoiceStatus, which is the version worth reading.
     */
    public function refreshBillStatus(PurchaseBill $bill): PurchaseBill
    {
        $status = $this->statusForBill($bill);

        if ($bill->status->isSettled()) {
            return $bill;
        }

        if ($bill->status !== $status) {
            $bill->forceFill(['status' => $status->value])->save();
        }

        return $bill;
    }

    /**
     * The note-adjusted balance, unclamped.
     *
     * Kept separate from figures() so the status rule below can ask whether the
     * balance is exactly zero - which is what distinguishes PAID from
     * PARTIALLY_PAID - without having to re-derive it, and so there is one
     * definition of "what is still owed on this document" rather than two.
     */
    private function balance(Money $grandTotal, Money $allocated, Money $netNotes): Money
    {
        /*
         * Subtracting netNotes is the sign convention, not an accident: a credit
         * note's net contribution is negative (credits - debits), so subtracting a
         * negative adds the credit back to the invoice. See the class docblock.
         */
        return $grandTotal->minus($allocated)->minus($netNotes);
    }

    /**
     * @return array{paid_total: string, balance_due: string}
     */
    private function figures(Money $grandTotal, Money $allocated, Money $netNotes): array
    {
        $balance = $this->balance($grandTotal, $allocated, $netNotes);

        /*
         * Clamped at zero, and the clamp now also covers a credit that exceeds the
         * outstanding balance. A note cannot make a document worth less than zero -
         * the adjustment service refuses a credit larger than the document - but a
         * document PAID IN FULL and then credited lands here at exactly -100, and
         * the credit is owed back to the counterparty rather than being money they
         * owe. "Nothing outstanding" is the right report of that, and it matches
         * what statusFromFigures concludes, so the two keys never disagree.
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
     * The status any document should be in, given its total, allocations and any
     * posted notes against it.
     *
     * Public so Phase 6's receivables/payables reports can label each outstanding
     * document without a second copy of this rule. The reports already have the
     * grand total and the allocated figure from their batch query; recomputing
     * the status from raw numbers must agree with the stored status that the
     * posting path wrote, and sharing this method is how the two are kept in
     * step.
     *
     * $netNotes is required rather than defaulted to zero. It is computed by the
     * adjustment service at every call site, so there is no real call that has
     * none - and defaulting it would let a caller that forgets it silently report
     * a status from pre-Phase-11 arithmetic, which is precisely the kind of
     * omission that produces a plausible wrong number instead of an error.
     */
    public function statusFromFigures(
        Money $grandTotal,
        Money $allocated,
        Money $netNotes,
    ): TransactionStatus {
        if ($allocated->isZero()) {
            return TransactionStatus::Posted;
        }

        /*
         * WHEN NOTHING HAS BEEN ALLOCATED, POSTED - AND THAT IS NOT THE SAME AS
         * PARTIALLY_PAID
         *
         * Before Phase 11 this method returned PAID whenever allocated >= grand
         * total, which for an invoice with no receipts at all means 0 >= 200. That
         * was true and useless: it reported every untouched invoice in the company
         * as fully paid, and the receivables report - which exists precisely to list
         * invoices with money outstanding - came back empty on a clean dataset.
         *
         * An invoice nobody has paid anything towards is POSTED, which is what it
         * has always been called everywhere else. PAID is reserved for a document
         * that has actually had money against it.
         *
         * A PARTIALLY_PAID status and a fresh credit note reach this method only
         * from the read paths - statusForInvoice, statusForBill - because the note
         * posting service never rewrites a settled document's status. See
         * refreshInvoiceStatus for why it does not, and why a credit note issued
         * after a receipt is settled is a customer-side question rather than one
         * this column is allowed to answer.
         *
         * Paid when nothing is outstanding, rather than when paid == total. After
         * Phase 11 those are different tests: a document paid in full and then
         * credited has paid == total and a positive balance, and calling it PAID
         * would contradict the balance_due sitting beside it in the same response.
         *
         * Over-allocation is still rejected when the allocation is written, so the
         * negative-balance branch here is a backstop against data that predates a
         * rule change rather than the enforcement point - and reporting PAID is the
         * honest reading of a document with nothing outstanding, credit or not.
         */
        if (! $this->balance($grandTotal, $allocated, $netNotes)->isPositive()) {
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
     * Net posted note adjustment per source document, in ONE query.
     *
     * The list endpoints attach figures to a whole page at a time, so asking the
     * adjustment service per document would put one query per row back - which is
     * exactly the cost the attach* methods exist to remove. So the grouped sum is
     * issued once here.
     *
     * It duplicates the sign CASE that CreditDebitNoteAdjustmentService uses, and
     * that duplication is deliberate and narrow: the alternative was either giving
     * the adjustment service a batch method it would serve for no other caller, or
     * reimplementing the sum in this class. One CASE expression in two files, both
     * of which are about deriving a balance, is a smaller risk than a batch API
     * invented for one use - and the two are checked against each other by
     * PhaseCreditDebitNoteSettlementTest, which asserts that a credit note moves
     * the balance by the same amount whichever path computed it.
     *
     * The document column is a parameter rather than an if/else, because the two
     * worlds differ only in which FK points at the source - there is no other
     * difference in how a note is summed.
     *
     * @param  array<int, int>  $documentIds
     * @param  'sales_invoice_id'|'purchase_bill_id'  $documentColumn
     * @return Collection<int, Money>
     */
    private function netNotesByDocumentIds(array $documentIds, string $documentColumn): Collection
    {
        if ($documentIds === []) {
            return new Collection;
        }

        $credits = implode(', ', array_map(
            fn (string $case) => "'".$case."'",
            [NoteType::SalesCreditNote->value, NoteType::PurchaseCreditNote->value]
        ));

        $rows = CreditDebitNote::query()
            ->whereIn($documentColumn, $documentIds)
            ->where('status', TransactionStatus::Posted->value)
            ->groupBy($documentColumn)
            ->selectRaw("{$documentColumn} as document_id, SUM(CASE WHEN note_type IN ({$credits}) THEN grand_total ELSE -grand_total END) as net")
            ->pluck('net', 'document_id');

        return $rows->map(fn ($net) => Money::of((string) $net));
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
