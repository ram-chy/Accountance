<?php

namespace App\Services\Sales;

use App\Enums\JournalSource;
use App\Enums\PaymentStatus;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Accounting\Currency\DocumentCurrencyService;
use App\Services\Accounting\Currency\RealizedFxResult;
use App\Services\Accounting\Currency\RealizedFxService;
use App\Services\Accounting\Currency\TransactionCurrency;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PaymentAllocationService;
use App\Services\Accounting\TransactionAccountResolver;
use App\Services\Audit\AuditService;
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
        private readonly DocumentCurrencyService $currencies,
        private readonly RealizedFxService $realizedFx,
        private readonly AuditService $audit,
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
                $this->assertInvoiceIsPayable(
                    $invoices[$invoiceId],
                    $customer->getKey(),
                    $fresh->currency_id === null ? null : (int) $fresh->currency_id,
                    $byInvoice[$invoiceId],
                );
            }

            $this->allocations->assertFullyAllocated($allocated, $fresh->amountMoney());

            /*
             * The currency of the money received, resolved HERE rather than read off
             * the draft. The draft's rate is a preview of a mutable rate table; this
             * is the last moment the receipt can be priced, and the whole point of
             * realized FX is that the settlement is converted at the rate in force
             * on the day the money moves rather than the day it was typed.
             */
            $context = $this->currencies->resolve(
                $fresh->company,
                $fresh->currency_id,
                $fresh->receipt_date->toDateString(),
                'currency_id',
            );

            /*
             * What the receivables being settled were CARRIED at, as opposed to what
             * the cash is worth today. Read from the allocation rows rather than
             * recomputed from the invoice, because that is the figure the draft-time
             * validation was measured against and the one RealizedFxService is
             * defined against. For a base-currency receipt it equals the allocated
             * amount, so this is the same number the pre-Phase-14 code used.
             */
            $carryingBase = Money::zero();

            foreach ($allocations as $allocation) {
                $carryingBase = $carryingBase->plus($allocation->baseAmount());
            }

            $description = "Receipt {$fresh->receipt_number}";

            $realizedFx = null;

            $lines = $context->isForeign()
                ? $this->foreignLines(
                    $context,
                    $paymentAccount,
                    $receivable,
                    $fresh->company,
                    $fresh->amountMoney(),
                    $carryingBase,
                    $description,
                    $realizedFx,
                )
                : [
                    $this->currencies->baseJournalLine(
                        $paymentAccount->getKey(), $description, $fresh->amountMoney(), true
                    ),
                    $this->currencies->baseJournalLine(
                        $receivable->getKey(), $description, $carryingBase, false
                    ),
                ];

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $fresh->receipt_date->toDateString(),
                    'description' => $description,
                    'reference' => $fresh->receipt_number,
                    'source_type' => JournalSource::CustomerReceipt->value,
                    'source_id' => $fresh->getKey(),
                    'lines' => $lines,
                ],
            );

            $this->posting->post($journal, $actor, 'receipt_date');

            /*
             * A settlement that realised an FX gain or loss is recorded against the
             * receipt, using the same journalPosted() path the rest of the audit
             * system already defines for postings. Written inside the transaction, so
             * a posting that rolls back does not leave an audit row claiming it
             * happened - and only when something was actually realised, because a
             * foreign receipt priced at its carrying rate touches no FX account and is
             * an ordinary posting with nothing new to audit.
             */
            if ($realizedFx !== null && ! $realizedFx->isNone()) {
                $this->audit->journalPosted($journal, $actor);
            }

            /*
             * base_amount is what the ledger actually booked for the cash, read back
             * out of the journal rather than converted again here. See
             * DocumentCurrencyService::baseAmountBooked for why the journal is the
             * authority: a receipt whose stored base total disagreed with its own
             * entry by a unit in the last place would make the customer statement and
             * the ledger two different answers with nothing to choose between them.
             */
            $booked = $this->currencies->baseAmountBooked(
                $journal->lines,
                $paymentAccount->getKey(),
            );

            $fresh->forceFill([
                'status' => PaymentStatus::Posted->value,
                'journal_id' => $journal->getKey(),
                'base_amount' => ($booked ?? $fresh->amountMoney())->toDatabase(),
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
     * The entry for a receipt of foreign money: cash at today's rate, the receivable
     * at what it was carried at, and the difference between them as realized FX.
     *
     * WHY THE RECEIVABLE LEG IS A BASE AMOUNT AND NOT A FOREIGN ONE
     *
     * It would be tidier to keep all three lines in the foreign currency, and it
     * cannot be done. The rate the receivable leg must use is the rate the INVOICE
     * was booked at - 2.5 - while the only rate this journal can carry is the one
     * resolved for the journal's date - 3.0. Expressing the leg in USD at 3.0 would
     * relieve 300.00 of a balance carried at 250.00, and the 50.00 that vanished
     * would be exactly the gain this whole exercise exists to report.
     *
     * So the receivable is credited with its CARRYING base, as a base amount, and the
     * difference becomes an explicit third line. That is also why
     * DocumentCurrencyService::assertAccountAccepts lets a base line onto a
     * currency-restricted account: this leg is a base amount by accounting
     * necessity, not by convenience.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function foreignLines(
        TransactionCurrency $context,
        Account $paymentAccount,
        Account $receivable,
        Company $company,
        Money $amount,
        Money $carryingBase,
        string $description,
        ?RealizedFxResult &$realizedFx = null,
    ): array {
        $lines = [
            // The cash: received in the foreign currency, priced at the rate of today.
            $this->currencies->journalLine($context, $paymentAccount->getKey(), $description, $amount, true),
            // The receivable: credited at what the invoices were carried at.
            $this->currencies->baseJournalLine($receivable->getKey(), $description, $carryingBase, false),
        ];

        $fx = $this->realizedFx->compute(
            $this->currencies->toBase($amount, $context),
            $carryingBase,
        );

        $realizedFx = $fx;

        /*
         * A settlement at exactly the carrying rate produces a balanced two-line
         * entry and touches no FX account, which is the correct outcome rather than a
         * missing one - a zero-value gain line would be rejected by the journal's own
         * CHECK constraint anyway.
         */
        if ($fx->isNone()) {
            return $lines;
        }

        $lines[] = $this->currencies->baseJournalLine(
            $this->realizedFx->resolveAccount($company, $fx->isGain())->getKey(),
            $fx->description().' - '.$description,
            $fx->amount(),
            // A gain is income and a loss is an expense, in both directions, which is
            // what makes the difference() sign above meaningful without a second
            // convention to remember.
            ! $fx->isGain(),
        );

        return $lines;
    }

    /**
     * @throws ValidationException
     */
    private function assertInvoiceIsPayable(
        SalesInvoice $invoice,
        int $customerId,
        ?int $receiptCurrencyId,
        Money $amount
    ): void {
        if ($invoice->customer_id !== $customerId) {
            throw ValidationException::withMessages([
                'allocations' => "Invoice {$invoice->invoice_number} belongs to a different customer.",
            ]);
        }

        /*
         * Re-checked here, and not only where the allocation was written, because the
         * two can drift: a receipt drafted against a USD invoice may have been
         * re-denominated since, and a posting that did not notice would clear a USD
         * receivable with an IDR receipt and leave both sub-ledgers wrong with the
         * journal looking perfectly balanced.
         */
        if ((int) $invoice->currency_id !== (int) $receiptCurrencyId) {
            throw ValidationException::withMessages([
                'allocations' => sprintf(
                    'Invoice %s is in a different currency from this receipt and cannot be settled by it.',
                    $invoice->invoice_number
                ),
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
