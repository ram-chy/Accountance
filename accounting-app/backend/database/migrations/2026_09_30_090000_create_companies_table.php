<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('legal_name', 200)->nullable();

            // Identity / registration. Nullable because not every jurisdiction
            // issues a single number to every entity.
            $table->string('registration_number', 100)->nullable();
            $table->string('tax_number', 100)->nullable();

            // Contact.
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website', 255)->nullable();

            // Postal address. Stored as discrete parts rather than one blob so
            // invoices in later phases can format an address block per locale.
            $table->string('address_line_1', 200)->nullable();
            $table->string('address_line_2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();

            // ISO 3166-1 alpha-2, uppercase. char(2) not varchar so the column
            // physically enforces the format length.
            $table->char('country_code', 2)->default('US');

            // IANA identifier, e.g. "America/New_York". Validated server-side
            // against PHP's timezone database; never trusted from the client.
            $table->string('timezone', 64)->default('UTC');

            $table->string('date_format', 20)->default('Y-m-d');

            /*
             | Currency is intentionally a bare reference with no foreign key.
             | The currencies table belongs to the dedicated fiscal period and
             | currency phase; declaring the column now reserves the slot
             | without duplicating that engine or inventing a table for it.
             */
            $table->unsignedBigInteger('currency_id')->nullable();

            // Soft state only. Accounting records will reference companies, so
            // deactivation replaces deletion (see CompanyPolicy::delete).
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['is_active', 'name']);

            // Enforced in the database, not only by Rule::unique in the form
            // request. The request-level check is a SELECT followed by an
            // INSERT, so two concurrent creates of the same name can both pass
            // it; only a unique index makes the guarantee real.
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
