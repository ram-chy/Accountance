<?php

namespace App\Http\Requests\Accounting\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class CompleteBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reconciliation = $this->route('reconciliation');

        if ($reconciliation === null) {
            return false;
        }

        return $this->user()->can('complete', $reconciliation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
