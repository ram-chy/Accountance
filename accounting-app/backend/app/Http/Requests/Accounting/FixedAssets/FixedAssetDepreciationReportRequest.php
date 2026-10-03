<?php

namespace App\Http\Requests\Accounting\FixedAssets;

use App\Models\FixedAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filter the depreciation report.
 *
 * The date range is REQUIRED, unlike every other filter in this module. A report with
 * no range has to pick one - the current year, the last quarter - and whatever it
 * picked would be a silent decision about which charges the reader is looking at. The
 * endpoint is a report over a period, so the period is an input.
 *
 * The range is matched against each charge's own period dates, not against the date
 * its journal posted: a run performed in arrears charges months that have elapsed, and
 * grouping by when the button was pressed would file last quarter's depreciation under
 * this quarter. That choice lives in the query, and this request only bounds the
 * inputs to it.
 *
 * `from` after `to` is refused here because it is decidable from the payload alone and
 * would otherwise produce an empty report that looks like an absence of data.
 */
class FixedAssetDepreciationReportRequest extends FormRequest
{
    use ValidatesFixedAssetInputs;

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', FixedAsset::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'fixed_asset_category_id' => [
                'nullable',
                'integer',
                Rule::exists('fixed_asset_categories', 'id')->where('company_id', $this->activeCompanyId()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end of the reporting period must not be before its start.',
            'fixed_asset_category_id.exists' => 'The selected category does not exist in the active company.',
        ];
    }
}
