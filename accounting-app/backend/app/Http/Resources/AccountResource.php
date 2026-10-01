<?php

namespace App\Http\Resources;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
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
            /*
             * The effective normal balance, not just the raw override. A client
             * rendering an account row needs to know which side the balance sits
             * on, and that is a property of account_type plus the contra
             * override - not something the caller should have to re-derive from
             * two fields and risk getting backwards.
             */
            'normal_balance' => $this->normalBalance()->value,
            /*
             * Whether normal_balance was explicitly overridden, so a client can
             * tell a contra account from an ordinary one rather than inferring
             * it from a null.
             */
            'is_contra' => $this->isContra(),
            'parent_id' => $this->parent_id,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'has_journal_history' => $this->hasJournalHistory(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
