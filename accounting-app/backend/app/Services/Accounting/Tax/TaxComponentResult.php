<?php

namespace App\Services\Accounting\Tax;

use App\Support\Money;

/**
 * One tax's contribution to a calculated amount.
 *
 * A value object, not a model, and deliberately so. A caller that receives a
 * component must not be able to mutate the tax, its rate or its account mapping
 * through it, and must not be able to save one. Everything here is a copy of
 * what was true at calculation time.
 *
 * It carries the tax's identity (id, code, name, type) alongside the rate and
 * the money, because a caller that receives "3.9800" cannot act on it: the useful
 * output is "3.9800 of VAT at 20% on 19.90, and here is the liability account to
 * credit". Separating those means every caller reassembles them, and a caller
 * that forgets the account books an unbalanced journal.
 */
final readonly class TaxComponentResult
{
    public function __construct(
        public int $taxId,
        public string $taxCode,
        public string $taxName,
        public string $taxType,
        public ?int $taxRateId,
        public Money $rate,
        public Money $taxableAmount,
        public Money $taxAmount,
        public ?int $outputAccountId,
        public ?int $inputAccountId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tax_id' => $this->taxId,
            'tax_code' => $this->taxCode,
            'tax_name' => $this->taxName,
            'tax_type' => $this->taxType,
            'tax_rate_id' => $this->taxRateId,
            'rate' => $this->rate->toDatabase(),
            'taxable_amount' => $this->taxableAmount->toDatabase(),
            'tax_amount' => $this->taxAmount->toDatabase(),
            'output_account_id' => $this->outputAccountId,
            'input_account_id' => $this->inputAccountId,
        ];
    }
}
