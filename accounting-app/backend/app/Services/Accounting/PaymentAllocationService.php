<?php

namespace App\Services\Accounting;

use App\Enums\PaymentStatus;
use App\Models\CustomerReceipt;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\SupplierPayment;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Validates and writes payment allocations, under row locks.
 *
 * The spec devotes a section (30) to this because it is the one place in Phase 5
 * where two legitimate users can silently corrupt each other's work: both see
 * an invoice with 500 outstanding, both allocate 500, and the invoice ends up
 * settled by 1000 unless something stops the second write.
 *
 * The rule that stops it is `SELECT ... FOR UPDATE` on the invoice row, taken
 * inside the same transaction that writes the allocation. Pre-flight validation
 * outside the transaction is not enough and is not relied on here at all: the
 * read that decides whether the allocation is legal must be the one whose result
 * the write is serialised against. With the lock, the second transaction blocks
 * until the first commits, then reads the freshly-updated allocation total and
 * rejects the over-allocation.
 *
 * Locking is deliberately on the *document* (invoice/bill), not on the payment.
 * The payment being written is already owned by the transaction that created it,
 * and the contention that matters is two payments racing for the same invoice.
 * Locking the invoice rows in a deterministic id order also prevents the
 * deadlock that locking them in request order would cause: two receipts
 * allocating against the same two invoices in opposite order would each hold the
 * lock the other needs.
 */
class PaymentAllocationService
{
    public function __construct(
        private readonly SettlementService $settlements,
    ) {}

    /**
     * Replace a receipt's allocations.
     *
     * A full replace, not a merge. A payment's allocation set is a statement of
     * "this receipt settles these invoices for these amounts", and a partial
     * update would leave the caller unable to express "actually drop the 20 I
     * put against invoice 3" without a separate endpoint whose only job would be
     * to decrement. Drafts are editable; posted receipts are not, which the
     * caller checks.
     *
     * MUST be called inside a transaction: the locks taken here are only held
     * until the enclosing transaction ends.
     *
     * @param  array<int, array{sales_invoice_id: int, amount: string|int|float}>  $allocations
     *
     * @throws ValidationException
     */
    public function replaceInvoiceAllocations(CustomerReceipt $receipt, array $allocations): void
    {
        $this->assertDraft($receipt->status, 'receipt');

        $incoming = $this->parseAllocations($allocations, 'sales_invoice_id');

        /*
         * Full allocation, not just "not more than the amount". See
         * assertFullyAllocated: a receipt debits cash for its whole amount and
         * credits AR only for what it settles, so any remainder would have to be
         * credited to an account this phase has no rule for.
         */
        $this->assertFullyAllocated($this->sum($incoming), $receipt->amountMoney());

        if ($incoming === []) {
            $receipt->allocations()->delete();

            return;
        }

        /*
         * Lock the invoices in ascending id order. Deterministic ordering is what
         * makes concurrent multi-invoice allocations safe: if two receipts
         * allocate against invoices 5 and 9, both take 5 then 9, so one waits for
         * the other instead of each holding a lock the other is waiting for.
         */
        $invoiceIds = array_keys($incoming);
        sort($invoiceIds);

        $invoices = SalesInvoice::query()
            ->where('company_id', $receipt->company_id)
            ->whereIn('id', $invoiceIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->assertAllFound($invoices, $invoiceIds, 'sales_invoice_id', 'invoice');

        $this->assertEveryAllocationAssignable(
            $allocations, $incoming, $invoices, $receipt->customer_id, 'sales_invoice_id',
            fn (SalesInvoice $invoice): Money => $this->outstandingFor($invoice),
        );

        $receipt->allocations()->delete();

        foreach ($incoming as $invoiceId => $amount) {
            $receipt->allocations()->create([
                'sales_invoice_id' => $invoiceId,
                'amount' => $amount->toDatabase(),
            ]);
        }
    }

    /**
     * Replace a supplier payment's allocations.
     *
     * @param  array<int, array{purchase_bill_id: int, amount: string|int|float}>  $allocations
     *
     * @throws ValidationException
     */
    public function replaceBillAllocations(SupplierPayment $payment, array $allocations): void
    {
        $this->assertDraft($payment->status, 'payment');

        $incoming = $this->parseAllocations($allocations, 'purchase_bill_id');

        $this->assertFullyAllocated($this->sum($incoming), $payment->amountMoney());

        if ($incoming === []) {
            $payment->allocations()->delete();

            return;
        }

        $billIds = array_keys($incoming);
        sort($billIds);

        $bills = PurchaseBill::query()
            ->where('company_id', $payment->company_id)
            ->whereIn('id', $billIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $this->assertAllFound($bills, $billIds, 'purchase_bill_id', 'bill');

        $this->assertEveryAllocationAssignable(
            $allocations, $incoming, $bills, $payment->supplier_id, 'purchase_bill_id',
            fn (PurchaseBill $bill): Money => $this->outstandingForBill($bill),
        );

        $payment->allocations()->delete();

        foreach ($incoming as $billId => $amount) {
            $payment->allocations()->create([
                'purchase_bill_id' => $billId,
                'amount' => $amount->toDatabase(),
            ]);
        }
    }

    /**
     * How much of a document is still unpaid, from posted payments only.
     *
     * Called with the document row already locked, so the answer cannot change
     * between this read and the write that follows it.
     */
    public function outstandingFor(SalesInvoice $invoice): Money
    {
        return Money::of($invoice->grand_total)
            ->minus($this->settlements->allocatedToInvoice($invoice->getKey()));
    }

    /**
     * How much of a bill is still unpaid.
     */
    public function outstandingForBill(PurchaseBill $bill): Money
    {
        return Money::of($bill->grand_total)
            ->minus($this->settlements->allocatedToBill($bill->getKey()));
    }

    /**
     * Re-run every affected document's settlement status.
     *
     * Called after a payment is posted. The documents were already locked by the
     * caller, so this reads committed allocation totals and writes the status.
     *
     * Takes an iterable rather than an array because the caller holds an Eloquent
     * collection, and a Collection is iterable. Accepting only arrays would push
     * an ->all() call onto every caller to satisfy a type hint that buys nothing.
     *
     * @param  iterable<int, SalesInvoice>  $invoices
     */
    public function refreshInvoiceStatuses(iterable $invoices): void
    {
        foreach ($invoices as $invoice) {
            $this->settlements->refreshInvoiceStatus($invoice);
        }
    }

    /**
     * @param  iterable<int, PurchaseBill>  $bills
     */
    public function refreshBillStatuses(iterable $bills): void
    {
        foreach ($bills as $bill) {
            $this->settlements->refreshBillStatus($bill);
        }
    }

    /**
     * Parse and normalise an incoming allocation array.
     *
     * Collapses a repeated document id into one entry by rejecting it outright
     * rather than summing, because a payload that lists invoice 5 twice is
     * ambiguous - it may mean "50 then 50" or "50 total" - and a payment system
     * that guesses is a payment system that can be over-allocated by accident.
     * The database unique index on (receipt, invoice) backs this up.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<int, Money> document id => amount
     *
     * @throws ValidationException
     */
    private function parseAllocations(array $allocations, string $idField): array
    {
        $parsed = [];
        $errors = [];

        foreach (array_values($allocations) as $index => $allocation) {
            if (! is_array($allocation) || ! isset($allocation[$idField])) {
                $errors["allocations.{$index}"] = 'Each allocation must name a document and an amount.';

                continue;
            }

            $documentId = (int) $allocation[$idField];

            if (isset($parsed[$documentId])) {
                $errors["allocations.{$index}.{$idField}"] =
                    'The same document is allocated to more than once. Combine them into a single allocation.';

                continue;
            }

            try {
                $amount = Money::ofTolerant($allocation['amount'] ?? 0);
            } catch (\InvalidArgumentException) {
                $errors["allocations.{$index}.amount"] = 'This value is not a valid number.';

                continue;
            }

            if (! $amount->isPositive()) {
                $errors["allocations.{$index}.amount"] = 'An allocation must be greater than zero.';

                continue;
            }

            $parsed[$documentId] = $amount;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $parsed;
    }

    /**
     * The exact total of an incoming allocation array.
     *
     * Public because the receipt and payment services need to compare it against
     * the document amount before the allocations are written, and parsing the
     * array twice would mean two places that could disagree about what the
     * payload says.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     *
     * @throws ValidationException
     */
    public function totalOf(array $allocations, string $idField = 'sales_invoice_id'): Money
    {
        return $this->sum($this->parseAllocations($allocations, $idField));
    }

    /**
     * Assert a payment document's allocations account for the whole amount.
     *
     * This is stricter than "allocations may not exceed the amount", and the
     * reason is accounting rather than tidiness. A receipt credits Accounts
     * Receivable for what it allocates, but debits cash for the whole amount. If
     * the two differed, the difference would have to be credited somewhere - and
     * there is nowhere correct to put it in Phase 5. It is a customer advance, an
     * on-account balance, and the spec (section 12) explicitly declines to design
     * that mechanism here. Rather than invent an account or let the entry fail to
     * balance at posting time with a message the user cannot act on, an unallocated
     * remainder is refused at the point the user can still fix it.
     *
     * The alternative - requiring the receipt to be fully allocated only at
     * posting - would let a user build a draft that can never succeed.
     *
     * @throws ValidationException
     */
    public function assertFullyAllocated(Money $allocated, Money $paymentAmount): void
    {
        if ($allocated->greaterThan($paymentAmount)) {
            throw ValidationException::withMessages([
                'allocations' => sprintf(
                    'The allocations total %s, which is more than the %s received.',
                    $allocated,
                    $paymentAmount
                ),
            ]);
        }

        if ($allocated->lessThan($paymentAmount)) {
            throw ValidationException::withMessages([
                'allocations' => sprintf(
                    'The allocations total %s but the receipt is for %s. '
                    .'The unallocated %s has no account to be booked against: '
                    .'this phase has no customer-advance mechanism. '
                    .'Reduce the receipt to the allocated amount, or allocate the remainder.',
                    $allocated,
                    $paymentAmount,
                    $paymentAmount->minus($allocated)
                ),
            ]);
        }
    }

    /**
     * @param  Collection<int, SalesInvoice|PurchaseBill>  $found
     * @param  array<int, int>  $requestedIds
     *
     * @throws ValidationException
     */
    private function assertAllFound(Collection $found, array $requestedIds, string $field, string $label): void
    {
        foreach ($requestedIds as $id) {
            if (! $found->has($id)) {
                /*
                 * Same reasoning as the account resolver: a company-scoped lookup
                 * that finds nothing cannot distinguish "no such document" from
                 * "belongs to another company", and the reply must not.
                 */
                throw ValidationException::withMessages([
                    $field => "The selected {$label} does not belong to the active company.",
                ]);
            }
        }
    }

    /**
     * Validate every allocation, collecting all failures before throwing.
     *
     * A receipt spread over six invoices should not cost six submissions to fix.
     * Each message is re-keyed to allocations.N.sales_invoice_id, because
     * assertAssignable reports against the bare field name - correct for a
     * single-invoice form, useless when the user needs to know *which* of six
     * rows is wrong.
     *
     * @param  array<int, array<string, mixed>>  $allocations  the raw request rows, for indexes
     * @param  array<int, Money>  $incoming  document id => amount
     * @param  Collection<int, SalesInvoice|PurchaseBill>  $documents  keyed by id
     * @param  callable(SalesInvoice|PurchaseBill): Money  $outstanding
     *
     * @throws ValidationException
     */
    private function assertEveryAllocationAssignable(
        array $allocations,
        array $incoming,
        Collection $documents,
        int $counterpartyId,
        string $idField,
        callable $outstanding
    ): void {
        $indexes = $this->indexesByDocumentId($allocations, $idField);

        $errors = [];

        foreach ($incoming as $documentId => $amount) {
            try {
                $this->assertAssignable(
                    $documents[$documentId], $counterpartyId, $amount, $outstanding($documents[$documentId])
                );
            } catch (ValidationException $e) {
                $key = sprintf('allocations.%d.%s', $indexes[$documentId] ?? 0, $idField);

                foreach ($e->errors() as $messages) {
                    $errors[$key] = $messages;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Map each document id to the row index it arrived at.
     *
     * parseAllocations keys by document id and rejects duplicates, so the
     * original positions cannot be recovered from its return value alone.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     * @return array<int, int>
     */
    private function indexesByDocumentId(array $allocations, string $idField): array
    {
        $indexes = [];

        foreach (array_values($allocations) as $index => $allocation) {
            if (is_array($allocation) && isset($allocation[$idField])) {
                $indexes[(int) $allocation[$idField]] ??= $index;
            }
        }

        return $indexes;
    }

    /**
     * @throws ValidationException
     */
    private function assertAssignable(
        SalesInvoice|PurchaseBill $document,
        int $counterpartyId,
        Money $amount,
        Money $outstanding
    ): void {
        $isInvoice = $document instanceof SalesInvoice;
        $field = $isInvoice ? 'sales_invoice_id' : 'purchase_bill_id';
        $label = $isInvoice ? 'invoice' : 'bill';

        if ($document->status->isDraft()) {
            throw ValidationException::withMessages([
                $field => "This {$label} is still a draft and has no accounting entry to settle. Post it first.",
            ]);
        }

        /*
         * Both checks are needed. The counterparty check stops a receipt for one
         * customer settling another customer's invoice - which would leave the
         * first customer's balance untouched while moving the second one's, and
         * the ledger would then disagree with both parties' accounts. The status
         * check above is not a substitute for it, and vice versa.
         */
        $ownerField = $isInvoice ? 'customer_id' : 'supplier_id';

        if ($document->{$ownerField} !== $counterpartyId) {
            throw ValidationException::withMessages([
                $field => "This {$label} belongs to a different "
                    .($isInvoice ? 'customer' : 'supplier').'.',
            ]);
        }

        if ($amount->greaterThan($outstanding)) {
            $number = $document instanceof SalesInvoice
                ? $document->invoice_number
                : $document->bill_number;

            throw ValidationException::withMessages([
                $field => sprintf(
                    'Allocation of %s exceeds the %s outstanding on %s.',
                    $amount,
                    $outstanding,
                    $number
                ),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(PaymentStatus $status, string $label): void
    {
        if (! $status->isDraft()) {
            throw ValidationException::withMessages([
                $label => "This {$label} is posted. Its allocations are part of the accounting record "
                    .'and cannot be changed.',
            ]);
        }
    }

    /**
     * @param  array<int, Money>  $amounts
     */
    private function sum(array $amounts): Money
    {
        $total = Money::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }
}
