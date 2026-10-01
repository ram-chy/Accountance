<?php

namespace Database\Factories;

use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReceiptAllocation>
 */
class CustomerReceiptAllocationFactory extends Factory
{
    protected $model = CustomerReceiptAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_receipt_id' => CustomerReceipt::factory(),
            'amount' => 100,
        ];
    }
}
