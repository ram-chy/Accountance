<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only representation of a user that may ever leave the API.
 *
 * Fields are listed explicitly. Nothing is passed through blindly, so
 * `password`, `remember_token` and any future sensitive column are excluded
 * by construction rather than by remembering to hide them.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim($this->first_name.' '.$this->last_name),
            'email' => $this->email,
            'mobile_no' => $this->mobile_no,
            'email_verified' => $this->email_verified_at !== null,
            'is_active' => (bool) $this->is_active,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
