<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();

            /*
             | cascadeOnDelete on the membership itself: if a company is ever
             | removed, its membership rows have no meaning. Companies are not
             | hard-deleted in normal operation (see CompanyPolicy::delete), and
             | users are deactivated rather than deleted, so this cascade only
             | fires during teardown or in tests.
             |
             | It deliberately does NOT cascade any future accounting table:
             | those will reference companies and users with restrictOnDelete.
             */
            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The user's default company, used when a request supplies no
            // explicit company context.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            // One membership row per user/company pair. This is the database
            // level guarantee against duplicate associations, independent of
            // any application check.
            $table->unique(['company_id', 'user_id']);

            // A user belongs to many companies, so this only accelerates
            // "list my companies" lookups.
            $table->index(['user_id', 'is_default']);

            /*
             | At most one default per user, enforced by the database.
             |
             | MySQL has no partial/filtered unique index, so the usual
             | workaround is used: a generated column that equals user_id only
             | when the row is the default, and NULL otherwise. MySQL unique
             | indexes ignore NULLs, so any number of non-default rows coexist
             | while a second default row for the same user violates the index.
             |
             | The column is VIRTUAL rather than STORED because MySQL refuses to
             | add a foreign key to a table that contains a STORED generated
             | column; a virtual column computes on read and permits the foreign
             | keys above.
             |
             | Application code in CompanyService also enforces this inside a
             | transaction; this constraint is the backstop that survives a
             | concurrent request or a future code path.
             */
            $table->unsignedBigInteger('default_for_user')
                ->virtualAs('CASE WHEN `is_default` THEN `user_id` ELSE NULL END')
                ->nullable();

            $table->unique('default_for_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
