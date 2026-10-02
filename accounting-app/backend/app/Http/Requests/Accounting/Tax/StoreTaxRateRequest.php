<?php

namespace App\Http\Requests\Accounting\Tax;

use App\Models\Tax;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a rate for a tax.
 *
 * Overlap is deliberately NOT validated here. Two periods that intersect is a
 * rule about the tax's whole rate history rather than about this submission, and
 * it needs the existing rows compared against the incoming one - which
 * TaxRateService does inside a transaction, where the read cannot race a
 * concurrent write. Putting it here as a Rule would re-check it outside that
 * transaction and still leave a window.
 *
 * What this request does validate is the shape: a percentage the system can store,
 * and dates that parse.
 */
class StoreTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createRate', $this->route('tax'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * A percentage: 10 means 10%, not 0.1. Bounded here at 100 because a
             * rate at or above 100% cannot be applied to an inclusive gross - the
             * divisor (100 + rate) goes to zero and above it the extracted net is
             * negative. The same bound is re-checked in TaxRateService so a
             * console command is protected too.
             */
            'rate' => ['required', 'numeric', 'min:0', 'max:99.9999'],

            'effective_from' => ['required', 'date_format:Y-m-d'],

            /*
             * nullable, and null is the ordinary case: a rate with no end date is
             * the current rate. Ordering against effective_from is checked in
             * TaxRateService for the same reason overlap is - it needs the
             * resolved values, not the raw strings.
             */
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate.numeric' => 'The tax rate must be a number of percent, for example 7.5 for 7.5%.',
            'rate.max' => 'The tax rate must be less than 100%.',
            'rate.min' => 'The tax rate cannot be negative.',
            'effective_from.date_format' => 'The effective-from date must be in YYYY-MM-DD format.',
            'effective_to.date_format' => 'The effective-to date must be in YYYY-MM-DD format, or left empty for an open-ended rate.',
        ];
    }
}
