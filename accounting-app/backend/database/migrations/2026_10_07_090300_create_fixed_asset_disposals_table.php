<?php

use App\Support\Database\SchemaCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 - one row per disposed asset.
 *
 * At most one per asset, enforced by the primary unique constraint rather than by
 * status: DISPOSED is terminal, so a second disposal row would be a second
 * attempt to remove the same cost from the ledger. Making that a database fact
 * means it survives a retry that slips past the status check inside a service.
 *
 * THE ARITHMETIC IS STORED BECAUSE IT WAS DECIDED ONCE
 *
 * `carrying_value_at_disposal`, `proceeds`, `gain` and `loss` are stored rather
 * than recomputed from the ledger, and the distinction from fixed_assets is the
 * point. Accumulated depreciation is derived, because it changes every period and
 * re-deriving it is exact. These figures do not: carrying value at disposal is the
 * accumulated depreciation *as at that date*, which is a historical fact about one
 * moment, and a report that recomputed it from today's rows would answer a
 * different question than the one the disposal note records.
 *
 * `gain` and `loss` are also kept as two columns rather than one signed
 * `gain_or_loss`. Only one is ever non-zero, and the CHECK constraint below makes
 * that a fact rather than a convention - which means the disposal journal service
 * can ask "is there a gain to book?" without also having to check it is not
 * simultaneously a loss.
 *
 * PROCEEDS ARE NOT A CASH-BANK TRANSACTION
 *
 * A disposal entry is: Dr Accumulated Depreciation, Dr Loss (or Cr Gain), Dr
 * Payable to the buyer, Cr Fixed Asset. The money arrives afterwards and is
 * banked like any other receipt, through the cash and bank flow that already
 * exists. Recording proceeds here as though they were received would imply a
 * receipt that has not happened, and would double-count when it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_disposals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Terminal, so at most one row per asset. This is the constraint that
             * makes "dispose twice" impossible at the database level; the status
             * check in the service is the friendly version of the same rule.
             */
            $table->foreignId('fixed_asset_id')
                ->unique()
                ->constrained('fixed_assets')
                ->cascadeOnDelete();

            /*
                         | The date the company stopped owning the asset, which is also the day
                         | the entry posts. One column, not two, because those cannot differ: a
                         | sale cannot be recognised on a day the company still owns the thing.
                         | It is also the date the entry falls into for period enforcement, so it
                         | is business_date rather than created_at on purpose - a disposal entered
                         | in arrears for a closed period is refused by AccountingPeriodService
                         | exactly as any other document is.
                         */
            $table->date('disposal_date');

            /*
             | Why the asset went: SOLD, SCRAPPED, STOLEN, DONATED, OBSOLETE. Free
             | text rather than an enum for the same reason credit_debit_notes
             * .note_type and cash_bank_transactions.transaction_type are - the list
             | is open-ended and an unanticipated reason must not block recording the
             * disposal.
             */
            $table->string('reason', 100)->nullable();

            /*
             | The agreed sale price, before any costs of sale. Gains and losses are
             | computed against it directly; there is no separate deduction column
             | because a company that wants to record removal costs as a loss rather
             | than as a reduction in proceeds enters the lower figure, which is the
             | same journal either way.
             |
             | Zero is allowed and meaningful: scrapping an asset for scrap value,
             | or writing off an obsolete machine, has no proceeds and produces a
             * loss equal to the whole carrying value. The service requires the row to
             | be non-negative rather than positive for this reason.
             */
            $table->decimal('proceeds', 20, 4)->default(0);

            /*
             | WHERE THE PROCEEDS WENT, which is the missing half of the disposal
             | entry and the reason proceeds cannot simply be described in a comment.
             |
             | The entry is:
             |
             |   Dr Accumulated Depreciation   the asset's written-down value
             |   Dr Loss on Disposal           only if the sale fell short of it
             |   Dr this account              the proceeds
             |     Cr Fixed Asset             what the cost was
             |     Cr Gain on Disposal        only if the sale exceeded it
             |
             | Leaving the proceeds line out does not produce a simpler entry, it
             | produces an unbalanced one: with them removed, debits are
             | accumulated + loss and credits are cost + gain, and those are equal
             | only in the one case where the company receives nothing at all.
             *
             | Cash or bank when the buyer paid on the spot, the company's
             | receivable account when it did not - both ASSET accounts, which is the
             | only thing the resolver can check about the choice, since which of the
             | two is correct is a fact about the sale that the request carries.
             |
             | On the disposal rather than the asset because it varies per disposal:
             * the same van sold for cash in one year and on 30-day credit in the next
             * is an ordinary event, and forcing one answer for the asset's whole life
             * would be wrong half the time.
             */
            $table->foreignId('proceeds_account_id')
                ->constrained('accounts')
                ->restrictOnDelete();

            /*
             | Carrying value at the moment of disposal: original_cost less every
             | depreciation charge posted up to and including the disposal date. The
             | disposal entry debits accumulated depreciation for exactly this figure,
             | and the credit to the asset account removes original_cost, so the
             | difference between them lands in gain or loss.
             */
            $table->decimal('carrying_value_at_disposal', 20, 4);

            /*
             | Proceeds less carrying value, split so that neither can be negative.
             | At most one is non-zero, and CHECK-enforced below.
             */
            $table->decimal('gain', 20, 4)->default(0);

            $table->decimal('loss', 20, 4)->default(0);

            $table->foreignId('journal_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('disposed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('posted_at');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'disposal_date']);
            $table->index('journal_id');
        });

        /*
         * gain >= 0 and loss >= 0.
         *
         * A gain or a loss is a magnitude. Storing a negative loss would make
         * "did this disposal make money or lose it" a question about signs as well
         * as values, and would let a row claim to be both a gain and a loss.
         */
        SchemaCheck::add(
            'fixed_asset_disposals',
            'gain >= 0 and loss >= 0',
            'fixed_asset_disposals_signs_check'
        );

        /*
         * Not both at once.
         *
         * Exactly one of these three is true of every disposal: proceeds above the
         * carrying value (a gain), below it (a loss), or equal to it (neither, and
         * the entry is two lines). Stating it as "not both" rather than "exactly
         * one non-zero" is deliberate - the equal case is legitimate and common when
         * an asset is sold for precisely its written-down value.
         *
         * The gain and loss amounts are not checked against proceeds and carrying
         * value here. That arithmetic is the service's, and re-deriving it in SQL
         * would mean the exact-decimal rules of Money::of() had to be reproduced in a
         * CHECK expression - two implementations of one calculation, free to
         * disagree, which is the failure mode the CHECK constraints exist to avoid.
         */
        SchemaCheck::add(
            'fixed_asset_disposals',
            'not (gain > 0 and loss > 0)',
            'fixed_asset_disposals_exclusive_check'
        );

        SchemaCheck::add(
            'fixed_asset_disposals',
            'proceeds >= 0',
            'fixed_asset_disposals_proceeds_check'
        );

        SchemaCheck::add(
            'fixed_asset_disposals',
            'carrying_value_at_disposal >= 0',
            'fixed_asset_disposals_carrying_check'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_disposals');
    }
};
