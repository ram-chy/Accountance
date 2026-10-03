<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dispose of a fixed asset.
 *
 * The payload carries the facts of the sale, not the accounting of it. The carrying
 * value, the gain and the loss are all computed by FixedAssetDisposalService from the
 * posted depreciation rows - a client that could send a gain could book one that the
 * register would then disagree with. All three are absent from the rules, and the
 * service does not read them from the request.
 *
 * `proceeds` is optional and defaults to zero, which is the ordinary way to write an
 * asset off or scrap it. It cannot be negative: money flowing the other way would
 * turn a disposal into a purchase with a negative cost, which is not an operation
 * this module has.
 *
 * `proceeds_account_id` is required and must be an ASSET account - a bank or cash
 * account if the buyer paid on the spot, receivables if not. The eligibility check
 * lives in TransactionAccountResolver::proceedsAccount() because it is the same
 * "which account may receive money" question the report and the service both ask.
 */
class DisposeFixedAssetRequest extends FormRequest
{
    use ValidatesFixedAssetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('dispose', $this->route('fixedAsset'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'disposal_date' => ['required', 'date_format:Y-m-d'],
            'proceeds' => array_merge(['nullable'], $this->nonNegativeMoneyRule()),
            'reason' => ['nullable', 'string', 'max:100'],

            'proceeds_account_id' => $this->companyAccountRule(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proceeds.min' => 'Disposal proceeds cannot be negative. Enter zero for an asset scrapped for no money.',
            'proceeds_account_id.exists' => 'The proceeds account does not exist in the active company.',
        ];
    }
}
