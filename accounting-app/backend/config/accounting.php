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

];
