<?php

namespace Database\Factories;

use App\Enums\FinancialYearStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<FinancialYear>
 */
class FinancialYearFactory extends Factory
{
    protected $model = FinancialYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->startOfYear();

        return [
            'company_id' => Company::factory(),
            'name' => $start->format('Y').'-'.($start->year + 1),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->endOfYear()->toDateString(),
            'status' => FinancialYearStatus::Open->value,
        ];
    }

    /**
     * A year anchored on the configured fiscal start month, e.g. 2027-04-01 to
     * 2028-03-31 when start_month is 4. This is the shape the generator produces.
     */
    public function fiscal(Carbon|string $firstMonth): static
    {
        return $this->state(function () use ($firstMonth) {
            $start = $firstMonth instanceof Carbon
                ? $firstMonth->copy()->startOfMonth()
                : Carbon::parse($firstMonth)->startOfMonth();

            return [
                'name' => $start->format('Y').'-'.$start->copy()->addYearNoOverflow()->format('Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addYearNoOverflow()->subDay()->toDateString(),
            ];
        });
    }

    public function closed(?User $actor = null): static
    {
        return $this->state(fn () => [
            'status' => FinancialYearStatus::Closed->value,
            'closed_by' => $actor?->getKey(),
            'closed_at' => now(),
        ]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => FinancialYearStatus::Open->value]);
    }
}
