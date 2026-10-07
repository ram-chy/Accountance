<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - record, on the ledger line, which currency it came from.
 *
 * THE CENTRAL DECISION OF THIS PHASE, STATED PLAINLY
 *
 * `debit` and `credit` remain what they have always been: the BASE currency
 * amounts, and the only amounts any report reads. They are not renamed, not
 * deprecated, and not duplicated. There is no `base_debit`/`base_credit` pair
 * alongside a `debit`/`credit` pair, because two sets of amount columns on the
 * ledger means two sources of truth that can disagree, and a ledger whose rows
 * do not balance under either reading is unusable. The additive `base_` prefix
 * was considered and rejected: it would have been unambiguous, and it would also
 * have required rewriting every report, every trial balance and every test in the
 * project to rename a column that was never wrong.
 *
 * What is added is provenance, not a second ledger:
 *
 *  - currency_id         the currency the entry was TRANSACTED in
 *  - foreign_debit       the amount in that currency
 *  - foreign_credit      the amount in that currency
 *  - exchange_rate       the rate used, as a snapshot
 *
 * The relationship is exact and the database enforces it:
 *
 *     debit  = foreign_debit  * exchange_rate
 *     credit = foreign_credit * exchange_rate
 *
 * for every line that carries a currency, to the penny. That is the single most
 * important CHECK in this phase, because it is what makes the foreign columns
 * *evidence* rather than *narrative*. A currency column that could drift from the
 * ledger amount would let someone restate what a posted entry was for without
 * changing the entry - which is precisely the audit failure this phase exists to
 * close.
 *
 * WHY ALL FOUR ARE NULLABLE
 *
 * Because a base-currency document produces exactly the same journal as it did in
 * Phase 13: two amounts, both NULL foreign columns, NULL rate. Every pre-existing
 * row in this table is left untouched and stays valid, and the entire Phase 13
 * test suite continues to pass without a single behavioural change. "NULL means
 * base currency at rate 1" is a complete and unambiguous reading, not a gap.
 *
 * WHY THE RATE IS STORED AND NOT RE-DERIVED
 *
 * Because a rate table is mutable history. Re-deriving the rate that priced a
 * posted document from today's rate table would mean the same posted journal
 * reports two different base amounts on two different days, and the difference
 * would be indistinguishable from a fraud. The rate on the line is a snapshot, so
 * the journal is self-describing: you can always answer "at what rate was this
 * entered?" from the entry itself.
 *
 * WHY THE FK IS restrictOnDelete
 *
 * The currency is part of the meaning of the amount on the row. Deactivation is
 * the reversible path; deletion would erase the only record of what the numbers
 * were denominated in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            /*
             * The transaction currency of THIS line, or null for a base-currency
             * line. It is per-line rather than per-journal because a single journal
             * can mix currencies - a multi-currency payment clearing three
             * invoices, or an FX reclassification - and forcing one currency per
             * journal would make those unpostable.
             */
            $table->foreignId('currency_id')
                ->nullable()
                ->constrained('currencies')
                ->restrictOnDelete()
                ->after('credit');

            /*
             * The foreign-currency amount, one-sided exactly like debit/credit.
             *
             * NULL means "this line has no foreign amount", which for a
             * base-currency line is the normal case. It is NOT zero: a genuine zero
             * is impossible on a posted line (see the one-sided CHECK on the
             * original table), so NULL and 0.0000 cannot be confused.
             *
             * DECIMAL(20,4) - the currency's own minor unit, not the ledger's. A
             * JPY invoice has no third decimal place and a KWD one has three; the
             * column holds what the transaction was worth in the currency actually
             * used, and the rate converts it to the ledger's scale.
             */
            $table->decimal('foreign_debit', 20, 4)->nullable()->after('currency_id');
            $table->decimal('foreign_credit', 20, 4)->nullable()->after('foreign_debit');

            /*
             * The rate snapshot, DECIMAL(20,10) like exchange_rates.rate.
             *
             * Decimal here, never float: this value multiplies an amount that ends
             * up in a posted journal, so a representation error in it becomes a
             * visible ledger error rather than staying a detail of the calculation.
             */
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('foreign_credit');

            /*
             * The account statement and foreign-currency movement report both ask
             * "show me this account's foreign-currency lines", and the leading
             * column of that query is account_id, which is already indexed for it.
             * This composite serves the currency-scoped variant without forcing the
             * report to filter on the transaction currency of every row.
             */
            $table->index(
                ['account_id', 'currency_id'],
                'journal_lines_account_currency_index'
            );
        });

        /*
         * THE CONSISTENCY INVARIANT, ENFORCED BY THE DATABASE.
         *
         * Stated positively: a line either carries no foreign amounts at all, or it
         * carries a complete, self-consistent set. All four shapes below are
         * accepted:
         *
         *   base currency     debit=X     credit=0  foreign_debit=NULL     exchange_rate=NULL
         *   foreign debit     debit=X     credit=0  foreign_debit>0       exchange_rate=r>0
         *   foreign credit    debit=0     credit=X  foreign_credit>0      exchange_rate=r>0
         *
         * Everything else is refused, including the shapes that a partial
         * implementation would produce:
         *
         *   foreign_debit set but exchange_rate NULL   - an amount with no way to convert it
         *   both foreign_debit and foreign_credit set  - same impossible two-sided line
         *   foreign_debit=0                           - a rounded-away amount
         *   rate but no foreign amount                - a rate on a base line, which is noise
         *   rate <= 0                                  - a rate that would zero the conversion
         *
         * `exchange_rate = cast(round(debit / foreign_debit, 4) as decimal(20,10))`
         * is the whole check: the base amount must equal the foreign amount times
         * the stored rate, rounded to the ledger's scale. Written this way it is
         * exact - MySQL evaluates both sides in DECIMAL, so this is arithmetic
         * equality, not a tolerance.
         *
         * The check is deliberately NOT "within a tolerance". A tolerance invites
         * the accumulated drift that Phase 14 exists to prevent, and every rounding
         * in this system already happens at a known, single point - the rate
         * object's multiply - so the residual here is always exactly zero. If this
         * CHECK ever fires, the value is genuinely wrong, not merely imprecise.
         *
         * EVERY PREDICATE IS EXPLICITLY NULL-SAFE, and that is not stylistic.
         *
         * The first version of this expression used the same
         * ((foreign_debit > 0) <> (foreign_credit > 0)) one-sidedness idiom as the
         * original journal_lines check, and it silently accepted half the shapes it
         * was meant to refuse. The reason is SQL three-valued logic: for a foreign
         * DEBIT line, foreign_credit is NULL, so `foreign_credit > 0` is NULL, so
         * `(TRUE) <> (NULL)` is NULL, so every AND above it collapsed to NULL, and
         * a CHECK constraint rejects a row only when the expression evaluates to
         * FALSE - never when it evaluates to NULL. A NULL constraint result is a
         * pass.
         *
         * So a line whose base amount contradicted its foreign amount was accepted,
         * and a rate with no foreign amount was accepted. Both were caught by
         * testing this constraint against deliberately broken rows rather than
         * trusting that it looked right - which is why
         * JournalLineFxConstraintTest exists and asserts on every one of these
         * shapes.
         *
         * Hence the form below: `is null` / `is not null` guards, which are the only
         * predicates here that cannot return NULL, wrapping comparisons that are
         * then guaranteed to have non-null operands. The expression's result is
         * therefore always TRUE or FALSE, and FALSE means refused.
         */
        SchemaCheck::add(
            'journal_lines',
            '(
                currency_id is null
                and foreign_debit is null
                and foreign_credit is null
                and exchange_rate is null
             ) or (
                currency_id is not null
                and exchange_rate is not null
                and exchange_rate > 0
                and (
                    (
                        foreign_debit is not null
                        and foreign_credit is null
                        and foreign_debit > 0
                        and round(foreign_debit * exchange_rate, 4) = debit
                    )
                    or
                    (
                        foreign_credit is not null
                        and foreign_debit is null
                        and foreign_credit > 0
                        and round(foreign_credit * exchange_rate, 4) = credit
                    )
                )
             )',
            'journal_lines_fx_consistency_check'
        );

        /*
         * Foreign amounts are never negative, for the same reason debit and credit
         * are not: the opposite side is expressed by the other column. A negative
         * foreign_debit here would mean a foreign amount whose base debit and
         * foreign side point in opposite directions.
         */
        SchemaCheck::add(
            'journal_lines',
            '(foreign_debit is null or foreign_debit > 0) and (foreign_credit is null or foreign_credit > 0)',
            'journal_lines_foreign_non_negative_check'
        );
    }

    public function down(): void
    {
        /*
         * The CHECK constraints come off first. MySQL refuses to drop a column that
         * a check constraint references (error 3959), so these two statements are
         * not optional cleanup - without them the rollback fails with the column
         * still in place and the migration still marked as rolled back.
         */
        SchemaCheck::drop('journal_lines', 'journal_lines_fx_consistency_check');
        SchemaCheck::drop('journal_lines', 'journal_lines_foreign_non_negative_check');

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('journal_lines_account_currency_index');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['foreign_debit', 'foreign_credit', 'exchange_rate']);
        });
    }
};
