<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16 - budgets.
 *
 * A BUDGET IS A PLAN, NOT AN ACCOUNTING RECORD. Nothing in this table or its
 * lines is ever posted, and no column here is a balance or an actual. The ledger
 * of record remains journals/journal_lines; this table only says what a company
 * intends to earn and spend, and BudgetVarianceReportService compares that
 * intention with the real ledger at read time.
 *
 * WHY A VERSION IS A ROW RATHER THAN A SECOND TABLE
 *
 * The brief sketches Budget -> BudgetVersion -> BudgetLine. That three-table
 * shape was considered and rejected in favour of a version NUMBER on this table
 * plus a parent pointer. The reason is that a version has no life of its own: it
 * has the same code, the same name, the same company and the same year as the
 * budget it revises, and the only thing that distinguishes it is its place in the
 * chain. A separate table would store a foreign key and an integer and nothing
 * else, and every listing would need a join to answer "which budget is this
 * really". Here, (company_id, code, version_number) is unique, so "the same
 * budget, revised" is a query rather than a join, and an approved version can
 * never be overwritten because a new version is a new ROW.
 *
 * WHY company_id AND NOT A GLOBAL SCOPE
 *
 * Company isolation for accounting is enforced in the service and controller
 * layer against the active company, exactly as Account and Journal do it; there
 * is no global query scope. The route binding for `budget` (AppServiceProvider)
 * resolves within the active company so a foreign id 404s before a controller
 * runs, and every service query filters explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | The fiscal year the budget plans for. Required, because a budget
             | with no year has no period it can legitimately reference, and the
             | brief is explicit that budgeting must respect the existing fiscal
             | model rather than invent a second calendar. restrictOnDelete: a
             | year that has a budget must not be deleted out from under it.
             */
            $table->foreignId('financial_year_id')
                ->constrained('financial_years')
                ->restrictOnDelete();

            /*
             | A human code, unique per company per version. Two companies may
             | both call a budget "FY27 Opex"; the same company may not have two
             | version 1 budgets with one code.
             */
            $table->string('code', 50);

            $table->string('name', 150);

            /*
             | 1 for the first draft; each revision increments it. The unique
             | key below turns "two concurrent revisions of the same budget"
             | into a database refusal rather than a duplicate chain.
             */
            $table->unsignedInteger('version_number')->default(1);

            /*
             | The version this one revises, or null for an original. nullOnDelete
             | rather than restrict: deleting a discarded draft must not be
             | blocked by a later revision that points at it, and the history the
             | audit trail needs is not this pointer.
             */
            $table->foreignId('parent_budget_id')
                ->nullable()
                ->constrained('budgets')
                ->nullOnDelete();

            /*
             | DRAFT or APPROVED. A string rather than a database enum for the
             | same reason audit_logs.action is a string: the controlled
             | vocabulary lives in App\Enums\BudgetStatus, and adding a state
             | should not be a migration. The CHECK below keeps the column honest
             | against that vocabulary.
             */
            $table->string('status', 20)->default('DRAFT');

            $table->text('notes')->nullable();

            /*
             | created_by/updated_by are ordinary attribution. approved_by and
             | approved_at are written only by BudgetService::approve(), never by
             | a request body, which is why they are absent from the model's
             | fillable list.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'code', 'version_number']);
            $table->index(['company_id', 'financial_year_id']);
            $table->index(['company_id', 'status']);
        });

        SchemaCheck::add(
            'budgets',
            "status in ('DRAFT','APPROVED')",
            'budgets_status_check'
        );

        SchemaCheck::add(
            'budgets',
            'version_number >= 1',
            'budgets_version_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
