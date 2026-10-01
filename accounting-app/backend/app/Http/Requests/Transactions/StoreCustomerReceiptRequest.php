<?php

namespace App\Http\Requests\Transactions;

use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Services\CompanyContext;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a draft customer receipt.
 *
 * `amount` is accepted from the client, unlike an invoice's totals, and that is a
 * deliberate difference rather than an inconsistency. A receipt's amount is an
 * input, not a derived figure: the money arrived and someone stated how much.
 * What the server will not accept is an allocation set that disagrees with it -
 * so no combination of amount and allocations can produce a receipt whose journal
 * would not balance. That check is here rather than in a rule because it needs
 * the parsed amounts, not the raw strings.
 */
class StoreCustomerReceiptRequest extends FormRequest
{
    use ValidatesTransactionAmounts;

    public function authorize(): bool
    {
        return $this->user()->can('create', CustomerReceipt::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->activeCompanyId();

        return [
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('company_id', $companyId),
            ],

            'receipt_date' => ['required', 'date_format:Y-m-d'],
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
            'allocations.*.sales_invoice_id' => [
                'required',
                'integer',
                Rule::exists('sales_invoices', 'id')->where('company_id', $companyId),
            ],
            'allocations.*.amount' => $this->positiveMoneyRule(),
        ];
    }

    /**
     * Cross-field rules that a per-field rule cannot express.
     *
     * Only the two things that are decidable without a database read are checked
     * here: the allocations must add up to the receipt amount, and no invoice may
     * be listed twice. Whether each allocation fits that invoice's outstanding
     * balance needs a locked read of the invoice, and is enforced by
     * PaymentAllocationService at save time and again at posting time.
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
                if (! is_array($allocation) || ! isset($allocation['sales_invoice_id'])) {
                    continue;
                }

                $invoiceId = (int) $allocation['sales_invoice_id'];

                if (isset($seen[$invoiceId])) {
                    $validator->errors()->add(
                        "allocations.{$index}.sales_invoice_id",
                        'The same invoice is allocated to more than once. Combine them into a single allocation.'
                    );
                }

                $seen[$invoiceId] = true;
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
                    'The allocations total %s but the receipt is for %s. '
                    .'The unallocated remainder has no account to be booked against: '
                    .'this phase has no customer-advance mechanism.',
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
            'allocations.min' => 'A receipt must allocate its amount to at least one invoice.',
        ];
    }
}
