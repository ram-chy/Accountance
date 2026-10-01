<?php

namespace App\Http\Requests\Accounting;

use App\Models\FinancialYear;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Update a financial year's name and dates.
 *
 * A closed year is refused in FinancialYearService rather than here: "is this
 * year closed" is a state question, and duplicating the state rule in a Form
 * Request is how two implementations of one rule come to disagree. The request
 * validates shape only.
 *
 * The year must not be shrunk so that a period falls outside it - also a service
 * rule, for the same reason.
 */
class UpdateFinancialYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var FinancialYear $financialYear */
        $financialYear = $this->route('financialYear');

        return $this->user()->can('updateYear', $financialYear);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var FinancialYear $financialYear */
        $financialYear = $this->route('financialYear');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }

    /**
     * Cross-field ordering, stated once here so the message is specific.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $start = $this->input('start_date');
            $end = $this->input('end_date');

            if ($start !== null && $end !== null && strtotime($end) < strtotime($start)) {
                $validator->errors()->add(
                    'end_date',
                    'The financial year end date must not be before its start date.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_date' => 'start date',
            'end_date' => 'end date',
        ];
    }
}
