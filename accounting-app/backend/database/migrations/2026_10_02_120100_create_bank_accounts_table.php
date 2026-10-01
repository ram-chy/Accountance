<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 - bank operational metadata.
 *
 * Deliberately small, and deliberately separate from `accounts`.
 *
 * The split follows from what each thing actually is. "This account is a bank
 * account" is an accounting fact about the account and lives on `accounts` as
 * cash_bank_kind. "This bank account is at HDFC, current account 50100..., IFSC
 * ..." is operational metadata about a real-world institution, and it is the
 * kind of detail that differs between two companies that both bank with the
 * same bank. Putting a bank name and an account number on the chart of accounts
 * would mean every report that loads an account drags bank credentials-shaped
 * columns through with it.
 *
 * What is NOT here, by design, and not merely by omission of a migration:
 * internet banking credentials, PINs, OTPs, CVVs, card secrets, statement
 * imports, reconciliation and gateway integrations. Those are Phase 7's stated
 * out-of-scope list, and the absence of the columns is what makes that a
 * property of the schema rather than a promise in a document. There is nowhere
 * to put a banking password because there is no column to put it in.
 *
 * One row per bank account. A company cannot have two bank_accounts rows for the
 * same account, and cannot have one at all for a non-BANK account - both are
 * enforced by the unique index and by CashBankAccountService respectively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * The accounts row this describes. Unique, so one account has at most
             * one set of bank details - two rows would make "which branch is this
             * account at" ambiguous, and there is no legitimate reading where an
             * account is at two branches at once.
             *
             * cascadeOnDelete: the metadata has no meaning without the account, so
             * removing an unused account takes its bank details with it rather
             * than orphaning them. AccountService refuses to delete an account
             * that has accounting history, so this only ever fires for an account
             * no journal has ever touched.
             */
            $table->foreignId('account_id')
                ->unique()
                ->constrained('accounts')
                ->cascadeOnDelete();

            /*
             * Display name for the account as the bank presents it, which is not
             * necessarily the account's name in the chart. A company may call it
             * "Operating Account" and the bank may call it "Current A/c".
             */
            $table->string('account_name', 255);

            $table->string('bank_name', 255);

            /*
             * The bank's own reference for the account. Stored as a string
             * because a leading zero is part of an account number, not noise -
             * an integer column would silently drop it.
             */
            $table->string('account_number', 100)->nullable();

            $table->string('branch', 255)->nullable();

            /*
             * IFSC in India, or whatever the company's equivalent bank identifier
             * is. A plain nullable string rather than a country-specific column:
             * this phase models the field's purpose, and a CHECK that assumed one
             * country's format would be a rule the schema cannot justify.
             */
            $table->string('bank_identifier', 100)->nullable();

            /*
             * Whether the bank details may still be used for new movements.
             *
             * Separate from accounts.is_active on purpose. An account can be
             * deactivated centrally - by an accountant managing the chart - while
             * these particular details remain correct; conversely a closed bank
             * account should stop being selectable before anyone gets round to
             * the chart of accounts. Both flags must be satisfied, and they are
             * separately controlled.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
