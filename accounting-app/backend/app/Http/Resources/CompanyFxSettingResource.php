<?php

namespace App\Http\Resources;

use App\Models\CompanyFxSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyFxSetting
 */
class CompanyFxSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_id' => $this->company_id,
            'realized_gain_account_id' => $this->realized_gain_account_id,
            'realized_loss_account_id' => $this->realized_loss_account_id,
            /*
             * Derived, and sent so a client rendering a form does not have to infer
             * "configured" from two nulls. A company that has not configured FX is
             * a legal state, not an error.
             */
            'is_configured' => $this->isConfigured(),
            'realized_gain_account' => $this->whenLoaded('realizedGainAccount', fn ($account) => $account === null ? null : [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ]),
            'realized_loss_account' => $this->whenLoaded('realizedLossAccount', fn ($account) => $account === null ? null : [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ]),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
