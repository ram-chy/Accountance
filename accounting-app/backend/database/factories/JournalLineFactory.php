<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalLine>
 */
class JournalLineFactory extends Factory
{
    protected $model = JournalLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_id' => Journal::factory(),
            'account_id' => Account::factory(),
            'description' => null,

            /*
             * The default is a single debit line rather than 0/0.
             *
             * A bare factory definition has to satisfy
             * journal_lines_one_sided_check, which requires exactly one of the
             * two columns to be positive. Defaulting both to zero produces a row
             * the database refuses, so every test that uses the factory without
             * calling ->debit() or ->credit() would fail at insert time with a
             * constraint error rather than at the assertion that was supposed to
             * be testing something. The default is a valid debit so the factory is
             * usable on its own; the ->credit() state mirrors it for the other
             * side of the entry.
             */
            'debit' => '100.0000',
            'credit' => '0.0000',
            'line_number' => 1,
        ];
    }

    public function debit(string|int|float $amount): static
    {
        return $this->state(fn () => [
            'debit' => number_format((float) $amount, 4, '.', ''),
            'credit' => '0.0000',
        ]);
    }

    public function credit(string|int|float $amount): static
    {
        return $this->state(fn () => [
            'debit' => '0.0000',
            'credit' => number_format((float) $amount, 4, '.', ''),
        ]);
    }

    public function forAccount(Account $account): static
    {
        return $this->state(fn () => ['account_id' => $account->getKey()]);
    }

    public function lineNumber(int $number): static
    {
        return $this->state(fn () => ['line_number' => $number]);
    }
}
