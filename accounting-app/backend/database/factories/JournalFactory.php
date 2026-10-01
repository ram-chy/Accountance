<?php

namespace Database\Factories;

use App\Enums\JournalSource;
use App\Enums\JournalStatus;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Journal>
 */
class JournalFactory extends Factory
{
    protected $model = Journal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = Carbon::now()->startOfYear();

        return [
            'company_id' => Company::factory(),
            /*
             * Journal numbers are allocated by JournalNumberSequence at runtime,
             * never by the factory, because allocating one here would either
             * fabricate a number the counter never issued or corrupt the
             * counter's high-water mark. Tests that need a specific number set it
             * explicitly.
             */
            'journal_number' => 'JNL-TEST-'.$this->faker->unique()->numerify('######'),
            'journal_date' => $date->toDateString(),
            'description' => fake()->sentence(4),
            'reference' => null,
            'status' => JournalStatus::Draft->value,
            'source_type' => JournalSource::Manual->value,
            'source_id' => null,
            'created_by' => User::factory(),
            'posted_by' => null,
            'posted_at' => null,
        ];
    }

    public function onDate(Carbon|string $date): static
    {
        return $this->state(fn () => [
            'journal_date' => $date instanceof Carbon
                ? $date->toDateString()
                : Carbon::parse($date)->toDateString(),
        ]);
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => JournalStatus::Posted->value,
            'posted_by' => fn (array $attributes) => User::factory(),
            'posted_at' => now(),
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => JournalStatus::Draft->value,
            'posted_by' => null,
            'posted_at' => null,
        ]);
    }
}
