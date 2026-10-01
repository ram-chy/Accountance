<?php

namespace App\Http\Requests\Transactions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Post a draft purchase bill.
 *
 * No body rules at all, and that is the point. A posting endpoint takes no input
 * it is allowed to get wrong: the accounts, amounts, period and journal shape are
 * all derived from the stored document by PurchaseBillPostingService. An empty
 * rules() array means a client that sends {"status": "POSTED", "grand_total": 0}
 * is validated against nothing and those values are simply never read - the
 * service sets the status and the total itself.
 *
 * authorize() delegates to the `post` ability, which is a distinct grant from
 * `update`. Otherwise anyone who could correct a typo in a draft could also post
 * it, and the deliberate separation between "edit" and "commit to the ledger"
 * would be lost.
 */
class PostPurchaseBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('post', $this->route('bill'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
