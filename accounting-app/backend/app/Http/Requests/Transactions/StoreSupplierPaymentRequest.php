<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\SupplierPayment;
use App\Services\CompanyContext;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a draft supplier payment.
 *
 * The mirror of StoreCustomerReceiptRequest, including the deliberate difference
 * from an invoice: `amount` is a client input (the money actually left the bank),
 * while the allocation total is checked against it so no unallocated remainder can
 * reach a journal that has nowhere to put it.
 */
class StoreSupplierPaymentRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('create', SupplierPayment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')->where('company_id', $companyId),
            ],

            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount' => $this->positiveMoneyRule(),

            // Existence here; ASSET type and active status by the resolver.
            'payment_account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')->where('company_id', $companyId),
            ],

            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['required', 'array'],
            'allocations.*.purchase_bill_id' => [
                'required',
                'integer',
                Rule::exists('purchase_bills', 'id')->where('company_id', $companyId),
            ],
            'allocations.*.amount' => $this->positiveMoneyRule(),
        ];
    }

    /**
     * The two checks that need parsed amounts rather than raw strings: the
     * allocations must total the payment, and no bill may be listed twice.
     *
     * Whether each allocation fits that bill's outstanding balance needs a locked
     * read of the bill, and is enforced by PaymentAllocationService at save time
     * and again at posting time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allocations = $this->input('allocations');

            if (! is_array($allocations)) {
                return;
            }

            $seen = [];

            foreach (array_values($allocations) as $index => $allocation) {
                if (! is_array($allocation) || ! isset($allocation['purchase_bill_id'])) {
                    continue;
                }

                $billId = (int) $allocation['purchase_bill_id'];

                if (isset($seen[$billId])) {
                    $validator->errors()->add(
                        "allocations.{$index}.purchase_bill_id",
                        'The same bill is allocated to more than once. Combine them into a single allocation.'
                    );
                }

                $seen[$billId] = true;
            }

            $amount = $this->parseOrNull($this->input('amount'));
            $allocatedTotal = Money::zero();
            $readable = $amount !== null;

            foreach (array_values($allocations) as $allocation) {
                if (! is_array($allocation)) {
                    $readable = false;

                    break;
                }

                $parsed = $this->parseOrNull($allocation['amount'] ?? null);

                if ($parsed === null) {
                    $readable = false;

                    break;
                }

                $allocatedTotal = $allocatedTotal->plus($parsed);
            }

            if (! $readable) {
                return;
            }

            if (! $allocatedTotal->equals($amount)) {
                $validator->errors()->add('allocations', sprintf(
                    'The allocations total %s but the payment is for %s. '
                    .'The unallocated remainder has no account to be booked against: '
                    .'this phase has no supplier-advance mechanism.',
                    $allocatedTotal,
                    $amount
                ));
            }
        });
    }

    private function parseOrNull(mixed $value): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Money::ofTolerant($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    protected function activeCompanyId(): int
    {
        /** @var Company $company */
        $company = app(CompanyContext::class)->getOrFail();

        return $company->getKey();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allocations.min' => 'A payment must allocate its amount to at least one bill.',
        ];
    }
}
