<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name', 255);

            /*
             | Inclusive date range. A journal dated on start_date or end_date
             | belongs to this period, so overlap is tested as
             |   existing.start <= new.end AND existing.end >= new.start
             | which is closed-interval intersection rather than the strictly
             | adjacent form. Period 2027-01-01..2027-01-31 and 2027-02-01.. must
             | NOT be treated as overlapping, and this comparison allows that.
             */
            $table->date('start_date');
            $table->date('end_date');

            $table->string('status', 20)->default('OPEN');

            $table->timestamps();

            /*
             | One period name per company. Two periods both called "January"
             | in the same company is always a mistake, and the duplicate name
             | is the usual symptom of a double-submitted form.
             */
            $table->unique(['company_id', 'name']);

            /*
             | Posting looks up "the period containing this date", so the pair
             | is the hot path. The unique on name is not usable for it.
             */
            $table->index(['company_id', 'start_date', 'end_date']);

            $table->index(['company_id', 'status']);
        });

        SchemaCheck::add(
            'accounting_periods',
            'end_date >= start_date',
            'accounting_periods_date_order_check'
        );

        SchemaCheck::add(
            'accounting_periods',
            "status in ('OPEN','CLOSED')",
            'accounting_periods_status_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
