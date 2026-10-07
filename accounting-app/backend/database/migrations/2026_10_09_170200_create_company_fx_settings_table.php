<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 14 - per-company destination accounts for realised FX results.
 *
 * WHY A TABLE FOR TWO COLUMNS
 *
 * Because these are the only Phase 14 settings that are company-scoped policy
 * rather than a fact about a currency or a rate, and because they have to be
 * changeable - tax law moves, and so does the account a business books its FX
 * result through. The alternative of two more columns on company_settings was
 * rejected for a less obvious reason than "two tables is tidier": company_settings
 * already carries a `default_currency_id` that was reserved in Phase 1 and never
 * used, and adding a third currency-shaped column to that row would give the
 * table three unrelated currency concepts and no statement of which one governs.
 * A named table for the FX accounts says exactly what it holds.
 *
 * WHY BOTH ACCOUNTS ARE OPTIONAL AT THE SCHEMA LEVEL
 *
 * They are nullable here and mandatory in the service layer. The schema cannot
 * enforce "a company that posts foreign currency must have configured both",
 * because that depends on whether the company has any foreign-currency document -
 * which is not knowable from this row. What the schema CAN do, and what the CHECK
 * below does, is refuse the half-configured state: configuring a gain account
 * without a loss account is almost certainly a mistake, and it is a mistake whose
 * only symptom is a mysterious balance in a realised-loss posting months later.
 * A company that needs neither sets neither and posts no foreign currency.
 *
 * NO COMPANY.CURRENCY_ID HERE, DELIBERATELY
 *
 * The company's base currency lives on companies.currency_id, where Phase 1 put a
 * placeholder for it, and duplicating it into a settings table would create two
 * answers to "what is this company converted into". One column, one meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fx_settings', function (Blueprint $table) {
            $table->id();

            /*
             * One settings row per company, enforced by the unique index below
             * rather than by a nullable company_id: the row is always present, so
             * the column is NOT NULL and there is no "no settings yet" state to
             * confuse a query with.
             *
             * cascadeOnDelete because this is company-scoped configuration with no
             * independent history. If the company goes, so does its answer to
             * "where do FX gains go" - unlike a posted journal, which is why those
             * FKs below cascade nothing.
             */
            $table->foreignId('company_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Realised FX gain: credit this when a settlement clears less base
             * currency than the receivable or payable it settles carried. That is,
             * the foreign currency weakened, and the debt is worth less than it was
             * on the invoice - a gain to the entity, and a smaller asset.
             */
            $table->foreignId('realized_gain_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             * Realised FX loss: debit this when a settlement clears MORE base
             * currency than the carried balance - the foreign currency strengthened.
             *
             * restrictOnDelete on both: an account holding realised FX results is
             * referenced by the loss/gain configuration itself, and AccountService's
             * journal-line check would already refuse to delete it once it has been
             * used. The FK is the backstop under that, not a substitute for it.
             */
            $table->foreignId('realized_loss_account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->timestamps();
        });

        /*
         * Both or neither, as described above. Expressed as an equality of
         * nullness so it stays correct if the pair is ever extended with an
         * unrealised gain/loss account.
         */
        SchemaCheck::add(
            'company_fx_settings',
            '(realized_gain_account_id is null) = (realized_loss_account_id is null)',
            'company_fx_settings_gain_loss_paired_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fx_settings');
    }
};
