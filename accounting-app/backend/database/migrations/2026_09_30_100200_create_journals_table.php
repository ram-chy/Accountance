<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         | Journal numbers must never be reused, even if the highest-numbered
         | draft is deleted. Deriving the next number with MAX(journal_number)+1
         | would hand JNL-000007 to a second journal after the first was
         | removed, leaving two distinct documents sharing one identifier in the
         | audit trail. The counter is therefore a row that only ever moves
         | forward, and deletion of a journal does not touch it.
         */
        Schema::create('journal_number_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestamps();
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             | Human-facing reference, unique per company. JNL-000001. Zero
             | padded to a fixed width so string ordering matches numeric
             | ordering; the padding width is config('accounting...padding') and
             | the database enforces only uniqueness, not the format.
             */
            $table->string('journal_number', 50);

            /*
             | The accounting date, not the created_at timestamp. A journal
             | entered late for January must fall into January, which is why
             | this is a distinct column rather than derived from timestamps.
             */
            $table->date('journal_date');

            $table->text('description')->nullable();
            $table->string('reference', 255)->nullable();

            $table->string('status', 20)->default('DRAFT');

            /*
             | Future module provenance. Deliberately a nullable pair with no
             | foreign key: the referenced documents do not exist yet, and a FK
             | to a table that is not there would mean inventing one. Recorded
             | now so later modules can stamp their documents on without a
             | migration.
             */
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            /*
             | Set from the authenticated user at post time, never from the
             | request body. Nullable because it stays null while the journal is
             | a draft; NOT NULL would be wrong, since the poster does not exist
             | yet at creation time.
             */
            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'journal_number']);

            /*
             | Journal listings filter by company and date and are paginated;
             | period-close lookups filter by company, status and date. These are
             | the two real query shapes, so they are the two real indexes.
             */
            $table->index(['company_id', 'journal_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'status', 'journal_date']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        SchemaCheck::add(
            'journals',
            "status in ('DRAFT','POSTED')",
            'journals_status_check'
        );

        /*
         | The posting invariant, as a database fact: a journal is either fully
         | posted (status, posted_by and posted_at all set) or fully not. A row
         | claiming POSTED with no poster has no explanation and would make the
         | audit trail a liar.
         */
        SchemaCheck::add(
            'journals',
            "(status <> 'POSTED') or (posted_by is not null and posted_at is not null)",
            'journals_posted_fields_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
        Schema::dropIfExists('journal_number_sequences');
    }
};
