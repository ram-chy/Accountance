<?php

namespace App\Http\Requests\Accounting\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class ReopenBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reconciliation = $this->route('reconciliation');

        if ($reconciliation === null) {
            return false;
        }

        return $this->user()->can('reopen', $reconciliation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
