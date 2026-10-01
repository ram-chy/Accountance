<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a draft sales invoice.
 *
 * No body rules, by the same reasoning as PostPurchaseBillRequest: the posting
 * service derives the accounts, amounts, period and journal shape from the stored
 * document, and the `post` ability is a separate grant from `update`.
 */
class PostSalesInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('post', $this->route('invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
