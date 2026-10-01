<?php

namespace Database\Factories;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Company;
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
        return $this->state(fn () => ['status' => PeriodStatus::Closed->value]);
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => PeriodStatus::Open->value]);
    }
}
