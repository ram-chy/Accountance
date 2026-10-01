<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account that participates in cash/bank transactions, with its bank details.
 *
 * The account's identity and classification come first, because those are the
 * facts a client needs to decide whether it can be used at all. The bank details
 * follow as a nested object rather than being flattened onto the row: they are
 * optional, they exist only for BANK accounts, and a cash account legitimately
 * has none.
 *
 * No balance is exposed. An account's money is a question about journal lines,
 * answered by the Phase 6 reports, and there is nothing on this record that could
 * honestly answer it - see CashBankTransactionResource for the same reasoning.
 *
 * @mixin Account
 */
class CashBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type->value,
            'normal_balance' => $this->normalBalance()->value,
            'cash_bank_kind' => $this->cash_bank_kind?->value,
            'is_active' => $this->is_active,
            'description' => $this->description,

            /*
             * The bank details as a nested object, or null for a cash account or
             * a bank account whose details have not been recorded. Emitted as an
             * empty-shaped null rather than omitted so a client can distinguish
             * "no bank details" from "this endpoint does not tell you".
             */
            'bank_account' => $this->whenLoaded('bankAccount', fn () => $this->bankAccount === null
                ? null
                : [
                    'account_name' => $this->bankAccount->account_name,
                    'bank_name' => $this->bankAccount->bank_name,
                    'account_number' => $this->bankAccount->account_number,
                    'branch' => $this->bankAccount->branch,
                    'bank_identifier' => $this->bankAccount->bank_identifier,
                    'is_active' => $this->bankAccount->is_active,
                ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
