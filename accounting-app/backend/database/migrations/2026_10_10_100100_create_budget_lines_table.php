<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 - budget lines.
 *
 * One planned amount for one account in one accounting period. There is
 * deliberately NO actual/balance/total column: actuals are derived from
 * journals every time a variance report is requested, so this table cannot
 * become a stale second copy of the ledger. See PHASE_16_REPORT.md.
 *
 * THE NATURAL KEY IS THE UNIQUE INDEX
 *
 * (budget_id, account_id, accounting_period_id) is unique, and it is the
 * database - not the service - that is the real guard against a duplicate line.
 * Two concurrent requests creating the same combination both pass an
 * application-level exists() check and one then fails the insert; the unique
 * index is what actually makes "one succeeds, one is rejected" true.
 *
 * WHY account_id AND accounting_period_id ARE PLAIN FOREIGN KEYS
 *
 * They are columns on a child row with no company_id of their own, so the
 * company match cannot be expressed here; BudgetLineService resolves the account
 * and the period through the active company and refuses a foreign one. That is
 * the same division App\Models\Account documents: the storage layer enforces
 * referential integrity, the service layer enforces tenancy.
 *
 * Amount is a single non-negative magnitude on the account's NORMAL side - the
 * same sign convention the P&L report and LedgerService use - rather than a
 * separate debit/credit pair. See BudgetLine's docblock for why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('budget_id')
                ->constrained('budgets')
                ->cascadeOnDelete();

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            $table->foreignId('accounting_period_id')
                ->constrained('accounting_periods')
                ->restrictOnDelete();

            /*
             | DECIMAL(20,4), the one monetary precision in the system, and the
             | scale Money writes. Never a float. A plan that cannot be subtracted
             | from the ledger exactly is a plan that produces a variance nobody
             | can reconcile.
             */
            $table->decimal('amount', 20, 4)->default(0);

            $table->string('description', 500)->nullable();

            $table->timestamps();

            $table->unique(
                ['budget_id', 'account_id', 'accounting_period_id'],
                'budget_lines_natural_key_unique'
            );

            $table->index('account_id');
            $table->index('accounting_period_id');
        });

        SchemaCheck::add(
            'budget_lines',
            'amount >= 0',
            'budget_lines_amount_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
