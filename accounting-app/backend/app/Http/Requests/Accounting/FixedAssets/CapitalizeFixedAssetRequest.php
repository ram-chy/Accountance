<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Capitalise a draft fixed asset.
 *
 * No body rules. Everything the entry needs - the cost, the accounts, the method and
 * the date - is already on the stored asset, and FixedAssetService re-resolves and
 * re-validates all of it under a row lock before posting. Accepting any of it from
 * the request would let a caller post a journal for figures the asset does not hold.
 *
 * `capitalize` is a separate grant from `create` for the same reason `post` is
 * separate from `create` on a cash/bank transaction: recording an asset and putting
 * its cost into the ledger are different acts of trust.
 */
class CapitalizeFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('capitalize', $this->route('fixedAsset'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
