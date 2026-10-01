<?php

namespace App\Http\Resources;

use App\Models\AccountingPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountingPeriod
 */
class AccountingPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * accepts_postings is derived from the period's own status AND its
         * financial year's, because a period inside a closed year is not postable
         * even though its own status says OPEN. Reporting only the period status
         * would tell a client a date is postable when the posting boundary will
         * reject it, which is the sort of disagreement that makes an API feel
         * broken. See AccountingPeriodService::assertPostableDate(), which is the
         * authority this mirrors.
         */
        $year = $this->financialYear;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status->value,
            'accepts_postings' => $this->status->isOpen() && ($year === null || $year->isOpen()),
            'financial_year_id' => $this->financial_year_id,
            'financial_year' => $year === null ? null : [
                'id' => $year->getKey(),
                'name' => $year->name,
                'status' => $year->status->value,
            ],
            /*
             * Close and reopen audit. Present so a client can show who made the last
             * state change and when without a second request. Each pair is null while
             * its own state does not hold: a freshly-created period has neither, and
             * a period that has been through both transitions carries the reopener,
             * not a stale closer.
             */
            'closed_by' => $this->closed_by,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'reopened_by' => $this->reopened_by,
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
