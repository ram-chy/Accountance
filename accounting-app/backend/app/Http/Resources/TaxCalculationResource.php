<?php

namespace App\Http\Resources;

use App\Services\Accounting\Tax\TaxCalculationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tax calculation, rendered for a client.
 *
 * Wraps TaxCalculationResult rather than exposing the value object directly. The
 * object is deliberately a readonly copy of tax configuration rather than a
 * model, and this is the boundary where its internal shape becomes the documented
 * API contract - which is why the field names here are the ones the brief
 * specifies (taxable_amount, tax_components, total_tax, gross_amount) rather than
 * the PHP property names.
 *
 * Amounts are strings. Every other resource in this application sends decimals as
 * strings for the same reason: a JSON number is a double on the client, and a tax
 * figure that has been through one is no longer exact.
 *
 * @mixin TaxCalculationResult
 */
class TaxCalculationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TaxCalculationResult $result */
        $result = $this->resource;

        return [
            'basis' => $result->basis->value,
            'taxable_amount' => $result->taxableAmount->toDatabase(),
            'total_tax' => $result->totalTax->toDatabase(),
            'gross_amount' => $result->grossAmount->toDatabase(),
            'tax_components' => $result->components->map(fn ($component) => [
                'tax_id' => $component->taxId,
                'tax_code' => $component->taxCode,
                'tax_name' => $component->taxName,
                'tax_type' => $component->taxType,
                'tax_rate_id' => $component->taxRateId,
                'rate' => $component->rate->toDatabase(),
                'taxable_amount' => $component->taxableAmount->toDatabase(),
                'tax_amount' => $component->taxAmount->toDatabase(),
                /*
                 * Both account ids are sent on every component even though at most
                 * one is ever usable. A component has to be self-contained, and a
                 * client picking the destination account should not have to know
                 * which side the tax belongs to in order to read the right field.
                 */
                'output_account_id' => $component->outputAccountId,
                'input_account_id' => $component->inputAccountId,
            ])->all(),
        ];
    }
}
