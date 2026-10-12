<?php

namespace App\Http\Resources\Accounting\Controls;

use App\Services\Accounting\Controls\ControlFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ControlFinding
 */
class ControlFindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
