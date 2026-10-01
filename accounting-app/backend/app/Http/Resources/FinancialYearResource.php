<?php

namespace App\Http\Resources;

use App\Models\FinancialYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialYear
 */
class FinancialYearResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * The period count and open-period count are computed per request rather
         * than stored. A company has a handful of periods per year, so this is a
         * single small aggregate, and deriving it here means a year can never
         * disagree with its own periods - the failure mode a cached count would
         * eventually produce.
         *
         * `can_close` is stated rather than left for the client to infer, because
         * "all periods closed" is a rule the client would otherwise have to
         * reimplement, and reimplementing it badly produces a close button that
         * 422s.
         */
        $periodCount = $this->periods_count ?? $this->periods()->count();
        $openCount = $this->open_periods_count ?? $this->periods()->where('status', 'OPEN')->count();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status->value,
            'accepts_postings' => $this->isOpen(),
            'periods_count' => $periodCount,
            'open_periods_count' => $openCount,
            'can_close' => $this->isOpen() && $periodCount > 0 && $openCount === 0,
            'created_by' => $this->created_by,
            'closed_by' => $this->closed_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
