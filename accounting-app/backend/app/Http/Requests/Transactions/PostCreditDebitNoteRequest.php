<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a draft credit/debit note.
 *
 * No body rules, by the same reasoning as PostSalesInvoiceRequest: the posting
 * service derives the accounts, amounts, entry direction, period and journal shape
 * from the stored note and the document it adjusts, and the `post` ability is a
 * separate grant from `update`.
 *
 * In particular there is nothing here to confirm an amount, a tax account or a
 * journal shape - the client has no say in any of them. What it cannot influence is
 * the one thing that matters most: the adjustment limit is re-derived server-side
 * from the source document under a row lock, so a client cannot post a note larger
 * than the document it adjusts by asserting one.
 */
class PostCreditDebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('post', $this->route('creditDebitNote'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
