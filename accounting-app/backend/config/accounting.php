<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Financial Precision
    |--------------------------------------------------------------------------
    |
    | One precision for every monetary column in the system. It is defined
    | here rather than repeated in migrations so the scale can be reviewed in
    | one place and so the Money value object and the database agree.
    |
    | DECIMAL(20,4) holds 16 integer digits with 4 decimal places. Every
    | ISO 4217 fiat currency is covered (the widest is 3 decimal places), so
    | this is headroom rather than speculation. Float and double are never
    | used for money: 0.1 + 0.2 is not 0.3 in binary floating point, and an
    | accounting ledger that cannot add 0.1 to 0.2 correctly is not an
    | accounting ledger.
    |
    | Changing this later means a migration across every monetary column. That
    | is intentional - it must never happen silently.
    |
    */

    'precision' => [
        'total' => 20,
        'scale' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange Rate Precision
    |--------------------------------------------------------------------------
    |
    | A SEPARATE scale from the one above, and the reason is not tidiness - it is
    | that four decimal places is the wrong answer for a rate by a wide margin.
    |
    | An exchange rate here is always "units of the company's base currency per one
    | unit of the transaction currency", and the pairs where that number is small
    | are ordinary rather than exotic:
    |
    |     IDR -> USD   about 0.000062   stored at 4 places as 0.0001   (61% error)
    |     KRW -> USD   about 0.00072    stored at 4 places as 0.0007   (3% error)
    |     INR -> USD   about 0.0120     stored at 4 places as 0.0120   (rounding only)
    |
    | The first of those is the reason this key exists. A rate that is 61% wrong is
    | not a rounding artefact to be noticed at year end - it is a plausible-looking
    | number that misstates every foreign-currency document converted with it, and
    | the error scales with the amount rather than staying small.
    |
    | Ten places keeps the multiplication exact at the ledger's scale: a
    | four-decimal amount times a ten-decimal rate has fourteen decimal places, and
    | rounding that once at the end - which is what Money::product() does, and what
    | the journal_lines CHECK constraint verifies independently - lands on a value
    | that is correct rather than merely close.
    |
    | `scale` must match the DECIMAL(20,10) columns built by the Phase 14
    | migrations. App\Support\Rate reads this value, so the schema and the value
    | object cannot drift apart: widening the columns without widening this would
    | round silently, and narrowing it would reject valid rates.
    |
    */

    'exchange_rate' => [
        'total' => 20,
        'scale' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Journal Numbering
    |--------------------------------------------------------------------------
    |
    | Journal numbers are human-facing and sequential per company, formatted
    | as {prefix}{zero-padded sequence}. The sequence lives in its own table so
    | numbers are never reused: deleting the highest-numbered draft cannot
    | hand its number to a later journal, which would leave two different
    | documents sharing an identifier in the audit trail.
    |
    | A UUID is not used as the visible number. The primary key remains a
    | separate auto-increment column.
    |
    */

    'journal_number' => [
        'prefix' => 'JNL-',
        'padding' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Defensive bounds on entry size. A journal is a handful of lines; a
    | payload with thousands is not a journal, and accepting it would let one
    | request hold a database transaction open for an unbounded time.
    |
    */

    'limits' => [
        'max_lines_per_journal' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Decimal Input Tolerance
    |--------------------------------------------------------------------------
    |
    | Incoming amounts are rounded to the configured scale rather than rejected.
    | A client sending 0.00001 means "a very small amount", and rejecting the
    | whole journal over the fifth decimal place is worse for the user than
    | rounding to the precision the system can actually store. Rounding is
    | half-up, and the result is always checked again for the one-sided,
    | non-zero rules after rounding - so 0.00001 rounds to 0.0000 and is then
    | rejected as a zero-value line rather than silently becoming a real entry.
    |
    */

    'rounding' => [
        'mode' => 'half-up',
        'tolerate_extra_precision' => true,

        /*
         * Upper bound on the decimal places a request may submit.
         *
         * Tolerance needs a limit, or "accept extra precision" becomes "accept a
         * thousand-digit fraction" and the rounding step has to handle whatever
         * arrives. Ten places is well beyond any real currency rate and far past
         * the point where the extra digits carry meaning, so a value past it is
         * a client bug rather than a rounding case worth accommodating.
         */
        'max_input_decimals' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | Every Phase 6 report reads from posted journals and Phase 5 allocations.
    | Nothing here is a stored balance; the only configuration is presentation,
    | so a company can change an aging bucket without a schema or report change.
    |
    | The aging buckets are ranges of whole days past the document's due date.
    | `min` is inclusive and `max` is inclusive; `null` on `min` means "any
    | negative age", i.e. not yet due, which belongs in the first bucket; `null`
    | on `max` means open-ended. The list must stay ordered and contiguous, and
    | the first bucket's `max` is the only one that also catches not-yet-due
    | amounts. Overriding this is a config edit, not a release.
    |
    */

    'reports' => [
        'aging_buckets' => [
            ['key' => 'current', 'label' => 'Current (0-30)', 'min' => null, 'max' => 30],
            ['key' => 'days_31_60', 'label' => '31-60 days', 'min' => 31, 'max' => 60],
            ['key' => 'days_61_90', 'label' => '61-90 days', 'min' => 61, 'max' => 90],
            ['key' => 'days_91_120', 'label' => '91-120 days', 'min' => 91, 'max' => 120],
            ['key' => 'days_121_plus', 'label' => '121+ days', 'min' => 121, 'max' => null],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscal Year
    |--------------------------------------------------------------------------
    |
    | Phase 8 adds financial years above accounting periods. No fiscal
    | convention existed before this key, so April is the default the brief
    | describes rather than a rule this system already followed: a year runs
    | from the 1st of this month to the last day of the month eleven places
    | later, so 4 means 2027-04-01 to 2028-03-31.
    |
    | This is deliberately a single integer and not a fiscal-calendar engine.
    | It is the smallest mechanism that makes period generation configurable,
    | and every company in this deployment shares one calendar, so a per-company
    | calendar would be storage for a distinction nobody has asked for.
    |
    | Changing it affects period *generation* only. It never rewrites an
    | existing financial year or period, because those are dated facts that
    | already have journal history attached to them.
    |
    */

    'fiscal_year' => [
        'start_month' => (int) env('FISCAL_YEAR_START_MONTH', 4),
    ],

];
