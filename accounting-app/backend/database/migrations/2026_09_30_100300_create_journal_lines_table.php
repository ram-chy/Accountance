<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();

            /*
             | No company_id here, deliberately.
             |
             | Company scope is inherited from the parent journal. Duplicating it
             | would create a second source of truth that can disagree with
             | journals.company_id, and a disagreeing row is a cross-tenant
             | integrity bug rather than a performance win: the journal is the
             | record of ownership, and a ledger query already has to join it to
             | filter by status and period anyway. Company filtering therefore
             | goes through journals.company_id.
             |
             | The service layer additionally asserts that every line's account
             | belongs to the same company as the journal, so the account_id FK
             | alone cannot be used to reach another tenant's account.
             */
            $table->foreignId('journal_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | restrictOnDelete is the database half of the account-deletion rule.
             | AccountService refuses to delete an account that has any journal
             | lines, but this FK means even a raw SQL delete, a queued job or a
             | future code path that forgets the check cannot orphan posted
             | ledger history.
             */
            $table->foreignId('account_id')
                ->constrained()
                ->restrictOnDelete();

            $table->text('description')->nullable();

            /*
             | DECIMAL(20,4), matching config('accounting.precision') and every
             | other monetary column. Never FLOAT or DOUBLE: those store binary
             | approximations, so SUM(debit) = SUM(credit) could fail for
             | reasons that have nothing to do with the entries. MySQL's DECIMAL
             | is exact base-10, which is what an accounting sum needs.
             */
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);

            /*
             | 1-based ordinal preserving the order the user entered lines in.
             | Uniquely constrained so re-saving a draft cannot silently produce
             | two "line 3" rows and reorder an entry nobody can reconstruct.
             */
            $table->unsignedSmallInteger('line_number');

            $table->timestamps();

            $table->unique(['journal_id', 'line_number']);

            /*
             | Account statements and trial balance group by account across
             | journals, so account_id is the leading column of the hot path.
             */
            $table->index('account_id');
        });

        /*
         | Negative amounts are rejected outright (spec section 27). The opposite
         | side is expressed by putting the amount in `credit`, not by writing
         | -100 in `debit`, so a negative here is always a bug.
         */
        SchemaCheck::add('journal_lines', 'debit >= 0', 'journal_lines_debit_non_negative_check');
        SchemaCheck::add('journal_lines', 'credit >= 0', 'journal_lines_credit_non_negative_check');

        /*
         | One-sided and non-zero, as a database invariant:
         |   exactly one of debit/credit is greater than zero.
         | This rejects both forbidden shapes at once - a line with both sides
         | populated, and a meaningless 0/0 line - so the rule cannot be bypassed
         | by any writer. A line that had its amount rounded away to 0.0000 also
         | fails here, which is the correct outcome.
         */
        SchemaCheck::add(
            'journal_lines',
            '((debit > 0) <> (credit > 0))',
            'journal_lines_one_sided_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
