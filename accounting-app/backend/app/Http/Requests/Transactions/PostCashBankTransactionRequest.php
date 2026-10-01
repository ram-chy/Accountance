<?php

namespace App\Http\Requests\Transactions;

use App\Models\CashBankTransaction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a draft cash/bank transaction.
 *
 * No body rules, and `post` is a separate grant from `create`: the amount and
 * the accounts come from the stored draft, and CashBankPostingService is what
 * re-checks the account eligibility and duplicate-posting guards under lock.
 */
class PostCashBankTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CashBankTransaction $transaction */
        $transaction = $this->route('transaction');

        return $this->user()->can('post', $transaction);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
