<?php

namespace Database\Factories;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    protected $model = AccountingPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->startOfYear();

        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->month().' '.fake()->year(),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->endOfYear()->toDateString(),
            'status' => PeriodStatus::Open->value,
        ];
    }

    /**
     * Attach the period to a specific financial year.
     *
     * Optional in the factory on purpose. AccountingPeriodService::create() will
     * derive the right year from the configured fiscal calendar when a caller
     * does not name one, so a test that is not about the year hierarchy should
     * not have to build it.
     */
    public function forFinancialYear(FinancialYear $year): static
    {
        return $this->state(fn () => ['financial_year_id' => $year->getKey()]);
    }

    /**
     * A single calendar month, the shape most accounting periods take.
     */
    public function month(Carbon|string $month): static
    {
        return $this->state(function () use ($month) {
            $start = $month instanceof Carbon
                ? $month->copy()->startOfMonth()
                : Carbon::parse($month)->startOfMonth();

            return [
                'name' => $start->format('F Y'),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->endOfMonth()->toDateString(),
            ];
        });
    }

    /**
     * An arbitrary inclusive range.
     */
    public function between(Carbon|string $start, Carbon|string $end): static
    {
        return $this->state(function () use ($start, $end) {
            $from = $start instanceof Carbon ? $start->copy() : Carbon::parse($start);
            $to = $end instanceof Carbon ? $end->copy() : Carbon::parse($end);

            return [
                'start_date' => $from->startOfDay()->toDateString(),
                'end_date' => $to->startOfDay()->toDateString(),
            ];
        });
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => PeriodStatus::Closed->value,
            'closed_at' => now(),
        ]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => PeriodStatus::Open->value]);
    }
}
