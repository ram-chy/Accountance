<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 - operational cash and bank movements.
 *
 * This table is a record of *what a user asked for*, never a record of *what the
 * balance is*. Every financial total it could plausibly be accused of
 * duplicating is deliberately absent:
 *
 *   - no balance column
 *   - no running balance
 *   - no opening balance
 *   - no debit total, no credit total
 *
 * A cash/bank balance is derived from posted journal_lines by LedgerService, the
 * same way every other balance in this system is derived. A cached balance here
 * would be a second source of truth that could disagree with the journal lines it
 * summarises, and every disagreement would then need a maintenance job to detect
 * it - which is exactly the machinery this schema is built to avoid.
 *
 * The one link back to the accounting record is journal_id, and it is a
 * reference for traceability rather than a second copy of the entry: the journal
 * is where the debit and credit actually live.
 *
 * `amount` is the single figure the user stated. It is not derived, because a
 * deposit is an input rather than a computation - someone counted the money and
 * said how much there was. CashBankPostingService writes it to the journal as
 * written and JournalPostingService then proves the entry balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_bank_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Allocated from document_number_sequences with type
             * CASH_BANK_TRANSACTION, formatted CBN-000001. Unique per company
             * and never reused.
             *
             * One sequence for all three types of movement, so that "what was the
             * number of that transfer" has one answer rather than three
             * possibilities.
             */
            $table->string('transaction_number', 50);

            /*
             * DEPOSIT, WITHDRAWAL or TRANSFER.
             *
             * Set by which endpoint was called, never by the client. A deposit
             * silently reclassified as a transfer would post to a different pair
             * of accounts than the user asked for, and which side has to be a
             * cash/bank account depends on this value.
             */
            $table->string('transaction_type', 20);

            /*
             * The date the money moved. This becomes the journal_date, and it is
             * the column every report and every date filter uses - created_at is
             * when the row was typed, which is a different fact and is never the
             * accounting date.
             */
            $table->date('transaction_date');

            /*
             * Where the money came from. Always required, for all three types.
             *
             * For a transfer it must be a cash/bank account. For a deposit it is
             * the account the money is arriving from - the caller's explicit
             * choice, never a hard-coded offset, because "money arrived" does not
             * say whether it was capital introduced, a loan drawn or a suspense
             * account being cleared.
             *
             * A withdrawal uses it as the cash/bank account the money leaves.
             */
            $table->foreignId('source_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             * Where the money went. The mirror of source_account_id, and the
             * debited side of the journal in every case: money enters the
             * destination.
             *
             * A withdrawal names the receiving account here - an expense for a
             * bank charge, or another cash/bank account when a transfer is what
             * the user meant. The service decides which is acceptable from the
             * transaction type.
             */
            $table->foreignId('destination_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             * The amount moved. DECIMAL(20,4) to match Money and every other
             * monetary column in this schema.
             *
             * Positive by validation. Direction is carried by the debit/credit
             * sides of the journal, not by a sign here, so a negative amount
             * would be a second and ambiguous way of saying the same thing.
             */
            $table->decimal('amount', 20, 4);

            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();

            /*
             * DRAFT or POSTED.
             *
             * Two states only, reusing the same lifecycle as a receipt and a
             * payment. There is no PARTIALLY_PAID, because a cash movement is
             * either recorded or it is not - unlike an invoice, which can be
             * partly settled by several receipts.
             */
            $table->string('status', 20)->default('DRAFT');

            /*
             * The journal this transaction produced, set only by
             * CashBankPostingService and only on posting. Null while draft, and
             * nullOnDelete because the journal is the record: losing this link
             * (only possible by deleting the journal itself) must not delete a
             * transaction.
             */
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'transaction_number']);

            /*
             * Indexes follow the filters the listing endpoint actually offers.
             * company_id + transaction_date serves the default date range,
             * company_id + status the DRAFT/POSTED split, and the account indexes
             * the "show me this account's movements" question - which is the one
             * a cashier actually asks.
             *
             * Named explicitly rather than left to Laravel's convention because
             * the generated names exceed MySQL's 64-character identifier limit
             * ("cash_bank_transactions_company_id_source_account_id_transaction_date"
             * is 77). Short names that say what the index is for are also easier
             * to find in SHOW INDEX than a truncated auto-generated one.
             */
            $table->index(['company_id', 'transaction_date'], 'cash_bank_txn_company_date_index');
            $table->index(['company_id', 'status'], 'cash_bank_txn_company_status_index');
            $table->index(['company_id', 'source_account_id', 'transaction_date'], 'cash_bank_txn_company_source_date_index');
            $table->index(['company_id', 'destination_account_id', 'transaction_date'], 'cash_bank_txn_company_dest_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_bank_transactions');
    }
};
