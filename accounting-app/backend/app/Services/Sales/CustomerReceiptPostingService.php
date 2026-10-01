<?php

namespace App\Services\Sales;

use App\Enums\JournalSource;
use App\Enums\PaymentStatus;
use App\Exceptions\ConflictException;
use App\Models\CustomerReceipt;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PaymentAllocationService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posting a customer receipt.
 *
 * The entry, per the spec's section 12:
 *
 *   Dr Cash / Bank         receipt amount
 *       Cr Accounts Receivable  total allocated
 *
 * With full allocation required (see PaymentAllocationService::assertFullyAllocated)
 * the debit and the credit are the same figure, so the entry balances by
 * construction - but the amounts are still written independently rather than
 * reusing one variable, because the balance is JournalService's job to verify,
 * not this service's to assume.
 *
 * The accounting credit goes to the customer's own receivable account rather than
 * to a single company-wide AR account. That is the point of storing
 * receivable_account_id on the customer: one company's ledger can then show what
 * each customer owes without a report having to attribute a shared balance.
 *
 * After the journal is posted, every invoice this receipt touched has its
 * settlement status recomputed - while those invoice rows are still locked, so
 * the status written reflects committed allocations.
 */
class CustomerReceiptPostingService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalPostingService $posting,
        private readonly TransactionAccountResolver $accounts,
        private readonly PaymentAllocationService $allocations,
    ) {}

    /**
     * @throws ValidationException
     * @throws ConflictException
     */
    public function post(CustomerReceipt $receipt, User $actor): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $actor) {
            $fresh = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isPosted()) {
                throw new ConflictException(
                    message: 'This receipt is already posted. '
                        .'Record a reversing receipt instead of posting it again.',
                    errors: [
                        'receipt' => ['This receipt is already posted. '
                            .'Record a reversing receipt instead of posting it again.'],
                    ],
                );
            }

            $customer = $fresh->customer;

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'customer_id' => 'This receipt has no valid customer.',
                ]);
            }

            $paymentAccount = $this->accounts->payment($fresh->company, $fresh->payment_account_id);
            $receivable = $this->accounts->receivable($fresh->company, $customer->receivable_account_id);

            /*
             * Lock the invoices, in ascending id order, and re-validate every
             * allocation against the balance that is actually outstanding now.
             *
             * The draft-time check is not enough. Between saving a draft and
             * posting it, another receipt may have settled the same invoice - or
             * the invoice may have been deleted. This is the read whose result the
             * write is serialised against, which is what the spec's section 30
             * asks for.
             */
            $allocations = $fresh->allocations()->get();

            if ($allocations->isEmpty()) {
                throw ValidationException::withMessages([
                    'allocations' => 'This receipt has no allocations, so it would not settle anything.',
                ]);
            }

            $allocated = Money::zero();
            $byInvoice = [];

            foreach ($allocations as $allocation) {
                $byInvoice[$allocation->sales_invoice_id] = $allocation->amountMoney();
                $allocated = $allocated->plus($allocation->amountMoney());
            }

            $invoiceIds = array_keys($byInvoice);
            sort($invoiceIds);

            $invoices = SalesInvoice::query()
                ->where('company_id', $fresh->company_id)
                ->whereIn('id', $invoiceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($invoices->count() !== count($invoiceIds)) {
                throw ValidationException::withMessages([
                    'allocations' => 'One or more invoices on this receipt no longer exist.',
                ]);
            }

            foreach ($invoiceIds as $invoiceId) {
                $this->assertInvoiceIsPayable($invoices[$invoiceId], $customer->getKey(), $byInvoice[$invoiceId]);
            }

            $this->allocations->assertFullyAllocated($allocated, $fresh->amountMoney());

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $fresh->receipt_date->toDateString(),
                    'description' => "Receipt {$fresh->receipt_number}",
                    'reference' => $fresh->receipt_number,
                    'source_type' => JournalSource::CustomerReceipt->value,
                    'source_id' => $fresh->getKey(),
                    'lines' => [
                        [
                            'account_id' => $paymentAccount->getKey(),
                            'description' => "Receipt {$fresh->receipt_number}",
                            'debit' => $fresh->amount,
                            'credit' => '0',
                        ],
                        [
                            'account_id' => $receivable->getKey(),
                            'description' => "Receipt {$fresh->receipt_number}",
                            'debit' => '0',
                            'credit' => $allocated->toDatabase(),
                        ],
                    ],
                ],
            );

            $this->posting->post($journal, $actor);

            $fresh->forceFill([
                'status' => PaymentStatus::Posted->value,
                'journal_id' => $journal->getKey(),
                'posted_by' => $actor->getKey(),
                'posted_at' => now(),
            ])->save();

            /*
             * Only now, with the journal committed inside this transaction and
             * the invoice rows still locked, is each invoice's status
             * recomputed. A receipt that pays an invoice in full moves it to
             * PAID; one that pays part moves it to PARTIALLY_PAID.
             */
            $this->allocations->refreshInvoiceStatuses($invoices->values());

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertInvoiceIsPayable(SalesInvoice $invoice, int $customerId, Money $amount): void
    {
        if ($invoice->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'allocations' => "Invoice {$invoice->invoice_number} belongs to a different customer.",
            ]);
        }

        if ($invoice->status->isDraft()) {
            throw ValidationException::withMessages([
                'allocations' => "Invoice {$invoice->invoice_number} is still a draft. Post it before "
                    .'allocating a payment against it.',
            ]);
        }

        $outstanding = $this->allocations->outstandingFor($invoice);

        if ($amount->greaterThan($outstanding)) {
            throw ValidationException::withMessages([
                'allocations' => sprintf(
                    'Allocation of %s exceeds the %s outstanding on invoice %s.',
                    $amount,
                    $outstanding,
                    $invoice->invoice_number
                ),
            ]);
        }
    }
}
