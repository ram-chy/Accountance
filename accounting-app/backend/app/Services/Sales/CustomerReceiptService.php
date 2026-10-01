<?php

namespace App\Services\Sales;

use App\Enums\DocumentNumberType;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\User;
use App\Services\Accounting\DocumentNumberSequence;
use App\Services\Accounting\PaymentAllocationService;
use App\Services\Accounting\TransactionAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer receipts: draft lifecycle.
 *
 * A receipt is a document that says "this customer paid us this much on this
 * date into this account, and here is what it settles". The lifecycle is
 * deliberately two states, not four: DRAFT until posted, POSTED after. The
 * "partially paid / paid" language belongs to the invoices being settled, not to
 * the money movement itself - a receipt does not become "paid".
 *
 * Allocations are written through PaymentAllocationService so the locking and
 * over-allocation rules exist in exactly one place, whether they are reached
 * from a receipt or a supplier payment.
 */
class CustomerReceiptService
{
    public function __construct(
        private readonly DocumentNumberSequence $numbers,
        private readonly TransactionAccountResolver $accounts,
        private readonly PaymentAllocationService $allocations,
        private readonly CustomerService $customers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createDraft(Company $company, User $actor, array $data): CustomerReceipt
    {
        $customer = $this->resolveCustomer($company, $data['customer_id']);

        $this->accounts->payment($company, $data['payment_account_id']);

        return DB::transaction(function () use ($company, $actor, $data, $customer) {
            $number = $this->numbers->nextFor($company, DocumentNumberType::Receipt);

            $receipt = new CustomerReceipt([
                'customer_id' => $customer->getKey(),
                'receipt_date' => $data['receipt_date'],
                'amount' => $data['amount'],
                'payment_account_id' => $data['payment_account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $receipt->forceFill([
                'company_id' => $company->getKey(),
                'receipt_number' => $number,
                'status' => PaymentStatus::Draft->value,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->allocations->replaceInvoiceAllocations(
                $receipt,
                $data['allocations'] ?? []
            );

            return $receipt->refresh();
        });
    }

    /**
     * Update a draft receipt, including its allocations.
     *
     * @throws ValidationException
     */
    public function updateDraft(CustomerReceipt $receipt, Company $company, array $data): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $company, $data) {
            $fresh = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            if (array_key_exists('customer_id', $data)) {
                $fresh->customer_id = $this->resolveCustomer($company, $data['customer_id'])->getKey();
            }

            if (array_key_exists('payment_account_id', $data)) {
                $this->accounts->payment($company, $data['payment_account_id']);
                $fresh->payment_account_id = $data['payment_account_id'];
            }

            /*
             * Assigned field by field rather than through a filtered array, because
             * "absent" and "explicitly null" are different requests for a nullable
             * column: omitting reference should leave it alone, while sending
             * reference: null should clear it. A filter on `!== null` cannot tell
             * them apart and silently drops every explicit null.
             */
            foreach (['receipt_date', 'amount', 'reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fresh->{$field} = $data[$field];
                }
            }

            $fresh->save();

            if (array_key_exists('allocations', $data)) {
                $this->allocations->replaceInvoiceAllocations($fresh, $data['allocations']);
            } else {
                /*
                 * The amount may have been reduced without new allocations being
                 * sent. Re-checking the existing total against the new amount is
                 * the only way that combination is caught - a draft whose
                 * allocations exceed its amount would be refused at posting with
                 * an error the user could not act on from the form they just used.
                 */
                $this->allocations->replaceInvoiceAllocations($fresh, $this->existingAllocations($fresh));
            }

            return $fresh->refresh();
        });
    }

    /**
     * Delete a draft receipt and its allocations.
     *
     * Safe because a draft receipt has no journal. Its allocations disappear with
     * it via the cascade, and because they were never posted they were never
     * counted against any invoice's balance.
     *
     * @throws ValidationException
     */
    public function deleteDraft(CustomerReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt) {
            $fresh = CustomerReceipt::query()
                ->whereKey($receipt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            $fresh->delete();
        });
    }

    /**
     * The existing allocations in the shape the allocator expects.
     *
     * @return array<int, array{sales_invoice_id: int, amount: string}>
     */
    private function existingAllocations(CustomerReceipt $receipt): array
    {
        return $receipt->allocations()
            ->get()
            ->map(fn ($allocation) => [
                'sales_invoice_id' => $allocation->sales_invoice_id,
                'amount' => (string) $allocation->amount,
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function resolveCustomer(Company $company, int $customerId): Customer
    {
        $customer = Customer::query()
            ->where('company_id', $company->getKey())
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'The selected customer does not belong to the active company.',
            ]);
        }

        $this->customers->assertUsableForInvoicing($customer);

        return $customer;
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(CustomerReceipt $receipt): void
    {
        if (! $receipt->status->isDraft()) {
            throw ValidationException::withMessages([
                'receipt' => 'This receipt is posted. Its allocations are part of the accounting record '
                    .'and cannot be changed.',
            ]);
        }
    }
}
