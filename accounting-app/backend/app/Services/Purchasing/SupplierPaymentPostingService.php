<?php

namespace App\Services\Purchasing;

use App\Enums\JournalSource;
use App\Enums\PaymentStatus;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\Company;
use App\Models\PurchaseBill;
use App\Models\SupplierPayment;
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
 * Posting a supplier payment.
 *
 * The entry, per the spec's section 13:
 *
 *   Dr Accounts Payable     total allocated
 *       Cr Cash / Bank      payment amount
 *
 * Note the sides are the mirror of a customer receipt: cash is credited when it
 * leaves and debited when it arrives. Getting this backwards would leave both the
 * bank account and the payable balance moving in the direction that makes the
 * trial balance look plausible while the company quietly appears solvent.
 */
class SupplierPaymentPostingService
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
    public function post(SupplierPayment $payment, User $actor): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $actor) {
            $fresh = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($fresh->status->isPosted()) {
                throw new ConflictException(
                    message: 'This payment is already posted. '
                        .'Record a reversing payment instead of posting it again.',
                    errors: [
                        'payment' => ['This payment is already posted. '
                            .'Record a reversing payment instead of posting it again.'],
                    ],
                );
            }

            $supplier = $fresh->supplier;

            if ($supplier === null) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'This payment has no valid supplier.',
                ]);
            }

            $paymentAccount = $this->accounts->payment($fresh->company, $fresh->payment_account_id);
            $payable = $this->accounts->payable($fresh->company, $supplier->payable_account_id);

            $allocations = $fresh->allocations()->get();

            if ($allocations->isEmpty()) {
                throw ValidationException::withMessages([
                    'allocations' => 'This payment has no allocations, so it would not settle anything.',
                ]);
            }

            $allocated = Money::zero();
            $byBill = [];

            foreach ($allocations as $allocation) {
                $byBill[$allocation->purchase_bill_id] = $allocation->amountMoney();
                $allocated = $allocated->plus($allocation->amountMoney());
            }

            $billIds = array_keys($byBill);
            sort($billIds);

            $bills = PurchaseBill::query()
                ->where('company_id', $fresh->company_id)
                ->whereIn('id', $billIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($bills->count() !== count($billIds)) {
                throw ValidationException::withMessages([
                    'allocations' => 'One or more bills on this payment no longer exist.',
                ]);
            }

            foreach ($billIds as $billId) {
                $this->assertBillIsPayable(
                    $bills[$billId],
                    $supplier->getKey(),
                    $fresh->currency_id === null ? null : (int) $fresh->currency_id,
                    $byBill[$billId],
                );
            }

            $this->allocations->assertFullyAllocated($allocated, $fresh->amountMoney());

            // See CustomerReceiptPostingService for why the currency is re-resolved
            // here instead of read off the draft.
            $context = $this->currencies->resolve(
                $fresh->company,
                $fresh->currency_id,
                $fresh->payment_date->toDateString(),
                'currency_id',
            );

            $carryingBase = Money::zero();

            foreach ($allocations as $allocation) {
                $carryingBase = $carryingBase->plus($allocation->baseAmount());
            }

            $description = "Payment {$fresh->payment_number}";

            $realizedFx = null;

            $lines = $context->isForeign()
                ? $this->foreignLines(
                    $context,
                    $payable,
                    $paymentAccount,
                    $fresh->company,
                    $fresh->amountMoney(),
                    $carryingBase,
                    $description,
                    $realizedFx,
                )
                : [
                    $this->currencies->baseJournalLine(
                        $payable->getKey(), $description, $carryingBase, true
                    ),
                    $this->currencies->baseJournalLine(
                        $paymentAccount->getKey(), $description, $fresh->amountMoney(), false
                    ),
                ];

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $fresh->payment_date->toDateString(),
                    'description' => $description,
                    'reference' => $fresh->payment_number,
                    'source_type' => JournalSource::SupplierPayment->value,
                    'source_id' => $fresh->getKey(),
                    'lines' => $lines,
                ],
            );

            $this->posting->post($journal, $actor, 'payment_date');

            /*
             * As in CustomerReceiptPostingService: a settlement that realised an FX
             * difference is a financial event and is audited inside the transaction,
             * through the existing journalPosted() helper. A payment that settles at
             * the carrying rate realises nothing and is left unaudited here.
             */
            if ($realizedFx !== null && ! $realizedFx->isNone()) {
                $this->audit->journalPosted($journal, $actor);
            }

            // The ledger is the authority on what the cash was worth; see
            // CustomerReceiptPostingService for why it is read back rather than
            // reconverted.
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

            $this->allocations->refreshBillStatuses($bills->values());

            return $fresh->refresh();
        });
    }

    /**
     * The entry for a payment of foreign money, with the difference as realized FX.
     *
     * Mirror image of CustomerReceiptPostingService::foreignLines, but THE SIGN OF
     * THE DIFFERENCE IS INVERTED, and that is the one thing worth being careful
     * about here.
     *
     * RealizedFxService is defined for money coming IN: gain = base received less
     * base carried. A payment is base PAID, so paying 300.00 to discharge a liability
     * carried at 250.00 is a loss of 50.00 - the company gave up more than it owed.
     * Handing compute() the same argument order the receipt uses would report that
     * as a gain, which would leave the entry balanced and the FX result exactly
     * backwards. Hence the arguments are swapped rather than the result negated:
     * "carrying less paid" is what a gain means on the paying side, and it lets both
     * services keep the same gain-credits / loss-debits rule without a second
     * convention to remember.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function foreignLines(
        TransactionCurrency $context,
        Account $payable,
        Account $paymentAccount,
        Company $company,
        Money $amount,
        Money $carryingBase,
        string $description,
        ?RealizedFxResult &$realizedFx = null,
    ): array {
        $lines = [
            $this->currencies->baseJournalLine($payable->getKey(), $description, $carryingBase, true),
            $this->currencies->journalLine($context, $paymentAccount->getKey(), $description, $amount, false),
        ];

        $fx = $this->realizedFx->compute($carryingBase, $this->currencies->toBase($amount, $context));

        $realizedFx = $fx;

        if ($fx->isNone()) {
            return $lines;
        }

        $lines[] = $this->currencies->baseJournalLine(
            $this->realizedFx->resolveAccount($company, $fx->isGain())->getKey(),
            $fx->description().' - '.$description,
            $fx->amount(),
            ! $fx->isGain(),
        );

        return $lines;
    }

    /**
     * @throws ValidationException
     */
    private function assertBillIsPayable(
        PurchaseBill $bill,
        int $supplierId,
        ?int $paymentCurrencyId,
        Money $amount
    ): void {
        if ($bill->supplier_id !== $supplierId) {
            throw ValidationException::withMessages([
                'allocations' => "Bill {$bill->bill_number} belongs to a different supplier.",
            ]);
        }

        // See CustomerReceiptPostingService::assertInvoiceIsPayable for why this is
        // re-checked at posting rather than trusted from the allocation.
        if ((int) $bill->currency_id !== (int) $paymentCurrencyId) {
            throw ValidationException::withMessages([
                'allocations' => sprintf(
                    'Bill %s is in a different currency from this payment and cannot be settled by it.',
                    $bill->bill_number
                ),
            ]);
        }

        if ($bill->status->isDraft()) {
            throw ValidationException::withMessages([
                'allocations' => "Bill {$bill->bill_number} is still a draft. Post it before "
                    .'allocating a payment against it.',
            ]);
        }

        $outstanding = $this->allocations->outstandingForBill($bill);

        if ($amount->greaterThan($outstanding)) {
            throw ValidationException::withMessages([
                'allocations' => sprintf(
                    'Allocation of %s exceeds the %s outstanding on bill %s.',
                    $amount,
                    $outstanding,
                    $bill->bill_number
                ),
            ]);
        }
    }
}
