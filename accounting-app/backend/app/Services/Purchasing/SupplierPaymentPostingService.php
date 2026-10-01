<?php

namespace App\Services\Purchasing;

use App\Enums\JournalSource;
use App\Enums\PaymentStatus;
use App\Exceptions\ConflictException;
use App\Models\PurchaseBill;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\PaymentAllocationService;
use App\Services\Accounting\TransactionAccountResolver;
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
                $this->assertBillIsPayable($bills[$billId], $supplier->getKey(), $byBill[$billId]);
            }

            $this->allocations->assertFullyAllocated($allocated, $fresh->amountMoney());

            $journal = $this->journals->createDraft(
                $fresh->company,
                $actor,
                [
                    'journal_date' => $fresh->payment_date->toDateString(),
                    'description' => "Payment {$fresh->payment_number}",
                    'reference' => $fresh->payment_number,
                    'source_type' => JournalSource::SupplierPayment->value,
                    'source_id' => $fresh->getKey(),
                    'lines' => [
                        [
                            'account_id' => $payable->getKey(),
                            'description' => "Payment {$fresh->payment_number}",
                            'debit' => $allocated->toDatabase(),
                            'credit' => '0',
                        ],
                        [
                            'account_id' => $paymentAccount->getKey(),
                            'description' => "Payment {$fresh->payment_number}",
                            'debit' => '0',
                            'credit' => $fresh->amount,
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

            $this->allocations->refreshBillStatuses($bills->values());

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertBillIsPayable(PurchaseBill $bill, int $supplierId, Money $amount): void
    {
        if ($bill->supplier_id !== $supplierId) {
            throw ValidationException::withMessages([
                'allocations' => "Bill {$bill->bill_number} belongs to a different supplier.",
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
