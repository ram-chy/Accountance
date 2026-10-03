<?php

namespace App\Services\Accounting\Controls;

use App\Enums\ControlStatus;

/**
 * One thing an accounting control observed.
 *
 * A finding names the control that produced it, how serious it is, a sentence a
 * non-developer can act on, and - where the check produced one - the resource it
 * is about. That is the whole shape: the report is a list of these grouped by
 * control, so a finding is deliberately flat rather than carrying a computed
 * status or a parent.
 *
 * Readonly because a finding is a statement about what a check saw, not a record
 * to be edited; the only thing anyone does with one is serialise it.
 */
final readonly class ControlFinding
{
    /**
     * @param  array<string, mixed>  $details  Raw values behind the finding (amounts, dates, ids)
     */
    public function __construct(
        public string $controlCode,
        public ControlStatus $status,
        public string $description,
        public ?string $resourceType = null,
        public ?int $resourceId = null,
        public ?string $financialDate = null,
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'control_code' => $this->controlCode,
            'status' => $this->status->value,
            'description' => $this->description,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'financial_date' => $this->financialDate,
            'details' => $this->details,
        ];
    }
}
