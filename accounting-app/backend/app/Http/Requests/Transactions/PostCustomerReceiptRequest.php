<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a draft customer receipt.
 *
 * No body rules, and the `post` ability is a separate grant from `update`: the
 * amounts and accounts come from the stored draft, and the posting service is
 * what re-checks the allocation fit under lock.
 */
class PostCustomerReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('post', $this->route('receipt'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
