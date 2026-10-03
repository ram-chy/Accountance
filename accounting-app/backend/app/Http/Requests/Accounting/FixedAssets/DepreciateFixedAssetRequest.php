<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post the next depreciation charge for an asset.
 *
 * The asset's schedule supplies the period, the amount and the posting date; the only
 * thing a caller may influence is `as_of`, the date the question "has this period
 * ended yet" is asked against. It exists so a back-office run can charge up to a
 * chosen date - the end of a period being closed, say - rather than being pinned to
 * the day the button was pressed. Omitted, it defaults to today in the service.
 *
 * There is deliberately no `period_number` or `amount` rule. Which period is next is a
 * function of the posted rows, and letting a client name one would be letting it
 * choose the position in a sequence the server alone can see.
 */
class DepreciateFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('depreciate', $this->route('fixedAsset'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'as_of.date_format' => 'The as-of date must be in YYYY-MM-DD format.',
        ];
    }
}
