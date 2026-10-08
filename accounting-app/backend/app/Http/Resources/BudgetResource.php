<?php

namespace App\Http\Resources;

use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A budget version.
 *
 * `financial_year` and `lines` are emitted only when loaded, so a listing can
 * render the header facts without dragging every line along, and a show endpoint
 * can include them. `lines_count` is emitted only when the controller has counted
 * it. The parent/version fields are the version chain: `parent_budget_id` names
 * the version this one revises, and `version_number` places it in the sequence.
 *
 * Status is emitted as its backing string, and the approve attribution
 * (`approved_by`, `approved_at`) is present so a client can show who finalised an
 * approved plan - which is the point of approving it.
 *
 * @mixin Budget
 */
class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'financial_year_id' => $this->financial_year_id,
            'code' => $this->code,
            'name' => $this->name,
            'version_number' => $this->version_number,
            'parent_budget_id' => $this->parent_budget_id,
            'status' => $this->status->value,
            'notes' => $this->notes,

            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->toIso8601String(),

            'financial_year' => new FinancialYearResource($this->whenLoaded('financialYear')),
            'lines' => BudgetLineResource::collection($this->whenLoaded('lines')),
            'lines_count' => $this->whenCounted('lines'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
