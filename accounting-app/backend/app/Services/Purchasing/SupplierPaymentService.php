<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentNumberType;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Accounting\DocumentNumberSequence;
use App\Services\Accounting\PaymentAllocationService;
use App\Services\Accounting\TransactionAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Supplier payments: draft lifecycle.
 *
 * The mirror of CustomerReceiptService, differing in the direction of the money
 * and the name of the counterparty. Everything about the locking, the
 * over-allocation refusal and the two-state lifecycle is shared with receipts
 * through PaymentAllocationService rather than restated, because two copies of
 * an allocation rule is two chances for the two directions to drift apart.
 */
class SupplierPaymentService
{
    public function __construct(
        private readonly DocumentNumberSequence $numbers,
        private readonly TransactionAccountResolver $accounts,
        private readonly PaymentAllocationService $allocations,
        private readonly SupplierService $suppliers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function createDraft(Company $company, User $actor, array $data): SupplierPayment
    {
        $supplier = $this->resolveSupplier($company, $data['supplier_id']);

        $this->accounts->payment($company, $data['payment_account_id']);

        return DB::transaction(function () use ($company, $actor, $data, $supplier) {
            $number = $this->numbers->nextFor($company, DocumentNumberType::Payment);

            $payment = new SupplierPayment([
                'supplier_id' => $supplier->getKey(),
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'payment_account_id' => $data['payment_account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->forceFill([
                'company_id' => $company->getKey(),
                'payment_number' => $number,
                'status' => PaymentStatus::Draft->value,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->allocations->replaceBillAllocations($payment, $data['allocations'] ?? []);

            return $payment->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function updateDraft(SupplierPayment $payment, Company $company, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $company, $data) {
            $fresh = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            if (array_key_exists('supplier_id', $data)) {
                $fresh->supplier_id = $this->resolveSupplier($company, $data['supplier_id'])->getKey();
            }

            if (array_key_exists('payment_account_id', $data)) {
                $this->accounts->payment($company, $data['payment_account_id']);
                $fresh->payment_account_id = $data['payment_account_id'];
            }

            // Absent and explicit null are different requests; see the same
            // comment in CustomerReceiptService::updateDraft.
            foreach (['payment_date', 'amount', 'reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fresh->{$field} = $data[$field];
                }
            }

            $fresh->save();

            if (array_key_exists('allocations', $data)) {
                $this->allocations->replaceBillAllocations($fresh, $data['allocations']);
            } else {
                $this->allocations->replaceBillAllocations($fresh, $this->existingAllocations($fresh));
            }

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function deleteDraft(SupplierPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $fresh = SupplierPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($fresh);

            $fresh->delete();
        });
    }

    /**
     * @return array<int, array{purchase_bill_id: int, amount: string}>
     */
    private function existingAllocations(SupplierPayment $payment): array
    {
        return $payment->allocations()
            ->get()
            ->map(fn ($allocation) => [
                'purchase_bill_id' => $allocation->purchase_bill_id,
                'amount' => (string) $allocation->amount,
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function resolveSupplier(Company $company, int $supplierId): Supplier
    {
        $supplier = Supplier::query()
            ->where('company_id', $company->getKey())
            ->whereKey($supplierId)
            ->first();

        if ($supplier === null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'The selected supplier does not belong to the active company.',
            ]);
        }

        $this->suppliers->assertUsableForBilling($supplier);

        return $supplier;
    }

    /**
     * @throws ValidationException
     */
    private function assertDraft(SupplierPayment $payment): void
    {
        if (! $payment->status->isDraft()) {
            throw ValidationException::withMessages([
                'payment' => 'This payment is posted. Its allocations are part of the accounting record '
                    .'and cannot be changed.',
            ]);
        }
    }
}
