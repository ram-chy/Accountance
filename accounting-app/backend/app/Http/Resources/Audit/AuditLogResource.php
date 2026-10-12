<?php

namespace App\Http\Resources\Audit;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'actor_id' => $this->actor_id,
            'actor' => $this->whenLoaded('actor', function () {
                return [
                    'id' => $this->actor->id,
                    'name' => $this->actor->name,
                    'email' => $this->actor->email,
                ];
            }),
            'action' => $this->action?->value,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'request_id' => $this->request_id,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'before_data' => $this->before_data,
            'after_data' => $this->after_data,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
