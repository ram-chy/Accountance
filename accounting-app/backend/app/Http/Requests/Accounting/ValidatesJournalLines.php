<?php

namespace App\Http\Requests\Accounting;

use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared rules for the journal line array.
 *
 * Both create and update use the identical shape, and the rules for a line are
 * genuinely fiddly: one-sided, non-negative, non-zero, and an amount that must
 * survive decimal parsing. Putting them in one trait means the two endpoints
 * cannot drift apart - a rule added here applies to both, which is the only way
 * "create rejects X" and "update rejects X" stay true together.
 */
trait ValidatesJournalLines
{
    /**
     * @return array<string, mixed>
     */
    protected function journalLineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:2', 'max:'.config('accounting.limits.max_lines_per_journal', 500)],
            'lines.*' => ['required', 'array'],

            /*
             * account_id exists within the active company only. The controller
             * already resolved the company server-side, so a client cannot widen
             * this by sending its own company_id - there is no such parameter.
             */
            'lines.*.account_id' => [
                'required',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('company_id', $this->activeCompanyId()),
            ],
            'lines.*.description' => ['nullable', 'string', 'max:1000'],

            /*
             * Both amounts are validated as decimal strings, never as numbers.
             * A JSON number has already passed through a double by the time PHP
             * sees it, so accepting 'numeric' would quietly lose precision on the
             * way in.
             *
             * The decimal bound is the *input* bound from config, not the storage
             * scale. config/accounting.php documents that extra precision is
             * rounded half-up rather than rejected, and pinning this rule to the
             * stored scale of 4 would have contradicted that promise before
             * Money::ofTolerant() ever ran - the user would get a validation
             * error for the fifth decimal place the config says is tolerated.
             * The rounded value is then re-checked for one-sidedness and
             * non-zero-ness below, so a value that rounds away to nothing is
             * still rejected rather than becoming a zero-value line.
             */
            'lines.*.debit' => ['required', 'decimal:0,'.$this->maxInputDecimals()],
            'lines.*.credit' => ['required', 'decimal:0,'.$this->maxInputDecimals()],

            /*
             * Phase 17: optional analytical metadata on a line. Only the shape is
             * checked here - array of {dimension_id, value_id} pairs. Whether a
             * dimension/value exists, is active, belongs to the active company and
             * belongs to its partner is a cross-row question answered by
             * DimensionAssignmentValidator inside JournalService, which sees the
             * company and can collect every line's errors at once (the same split
             * the account owned-by-company rule uses).
             */
            'lines.*.dimensions' => ['sometimes', 'array'],
            'lines.*.dimensions.*' => ['required', 'array'],
            'lines.*.dimensions.*.dimension_id' => ['required', 'integer'],
            'lines.*.dimensions.*.value_id' => ['required', 'integer'],
        ];
    }

    /**
     * The widest decimal input the request layer will accept.
     */
    protected function maxInputDecimals(): int
    {
        return (int) config('accounting.rounding.max_input_decimals', Money::scale());
    }

    /**
     * Cross-line rules that `lines.*` cannot express.
     *
     * Runs after the per-line rules pass. Everything reported here is a
     * whole-journal property: the line count, the one-sided rule, and the
     * balance. Reporting all of them together means the user fixes every problem
     * in one pass instead of discovering them one submit at a time.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = $this->input('lines');

            if (! is_array($lines)) {
                return;
            }

            foreach ($lines as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $debit = $this->parseOrNull($line['debit'] ?? null);
                $credit = $this->parseOrNull($line['credit'] ?? null);

                if ($debit === null || $credit === null) {
                    // The 'decimal' rule has already reported the malformed
                    // value; adding an arithmetic complaint here would be noise.
                    continue;
                }

                if ($debit->isNegative() || $credit->isNegative()) {
                    $validator->errors()->add(
                        "lines.{$index}",
                        'A journal line cannot have a negative amount. '
                        .'Record the opposite side on the other column instead.'
                    );
                }

                if ($debit->isPositive() && $credit->isPositive()) {
                    $validator->errors()->add(
                        "lines.{$index}",
                        'A journal line cannot have both a debit and a credit. Split it into two lines.'
                    );
                }

                if ($debit->isZero() && $credit->isZero()) {
                    $validator->errors()->add(
                        "lines.{$index}",
                        'A journal line must have an amount greater than zero on one side.'
                    );
                }
            }

            $this->assertBalanced($validator, $lines);
        });
    }

    /**
     * The central rule, checked on the server from the submitted lines.
     *
     * Any client-supplied total or is_balanced flag is ignored entirely: the
     * server sums the lines itself. A client that computed its totals correctly
     * proves nothing, and one that did not must not be able to talk the server
     * into posting an unbalanced entry.
     */
    private function assertBalanced(Validator $validator, array $lines): void
    {
        $totalDebit = Money::zero();
        $totalCredit = Money::zero();
        $readable = true;

        foreach ($lines as $line) {
            if (! is_array($line)) {
                $readable = false;

                break;
            }

            $debit = $this->parseOrNull($line['debit'] ?? null);
            $credit = $this->parseOrNull($line['credit'] ?? null);

            if ($debit === null || $credit === null) {
                $readable = false;

                break;
            }

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
        }

        if (! $readable) {
            return;
        }

        if (! $totalDebit->equals($totalCredit)) {
            $validator->errors()->add('lines', sprintf(
                'The journal is not balanced. Total debit is %s and total credit is %s, '
                .'a difference of %s.',
                $totalDebit,
                $totalCredit,
                $totalDebit->minus($totalCredit)
            ));
        }
    }

    private function parseOrNull(mixed $value): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Money::ofTolerant($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    abstract protected function activeCompanyId(): int;
}
