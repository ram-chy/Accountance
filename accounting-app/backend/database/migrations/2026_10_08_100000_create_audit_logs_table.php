<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 - the centralized audit trail.
 *
 * ONE ROW IS ONE THING THAT HAPPENED: WHO did WHAT, WHEN, in WHICH company,
 * against WHICH resource. It is a record of actions, not a second copy of the
 * accounting data, and it is never the source of an accounting balance.
 *
 * WHY A NEW TABLE
 *
 * Before Phase 13 the only audit information was per-row attribution columns -
 * created_by, posted_by/posted_at, closed_by, disposed_by and the rest. Those
 * answer "who last touched this row" but they cannot answer "what happened to
 * this company last week", they are overwritten when the same row moves again
 * (a period closed, reopened and closed again keeps only the last pair), and
 * they leave no trace at all for actions on master data that has no such columns
 * (accounts). A single append-only table is the smallest structure that answers
 * the question the phase is about.
 *
 * WHY created_at AND NOT updated_at
 *
 * An audit record is immutable. There is no update, so there is no updated_at:
 * a timestamp that could only ever equal created_at would be a column that
 * invites the wrong operation. The model refuses updates and deletes outright.
 *
 * WHY company_id IS NULLABLE
 *
 * Financial and business events always carry a company. Security events - a
 * login, a password change - concern a user and a credential, and a user may
 * hold no company at all (a failed login for an address that does not exist, a
 * password reset before any membership). For those the company is null, and the
 * company-scoped audit API deliberately never returns null-company rows: they
 * are not the company's accounting history and must not be visible through a
 * company's audit endpoint. This is what keeps the isolation guarantee intact
 * while still recording the security event.
 *
 * actor_id and company_id use nullOnDelete rather than cascade: deleting a user
 * or a company must not destroy the history of what was done. The row survives
 * with the pointer cleared. That is also why they are nullable - the FK action
 * SET NULL requires it.
 *
 * WHAT IS NOT STORED
 *
 * No password, token, OTP, secret or authorization header is ever written here.
 * AuditService scrubs a fixed deny-list from before/after/metadata before insert
 * and the audit resource serializer is explicit rather than a raw model dump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             | One value from AuditAction. A string rather than a database enum so
             | adding an action is an application change, not a migration, and so
             | the controlled vocabulary lives in exactly one place.
             */
            $table->string('action', 30);

            /*
             | The resource the action was performed on, as a class name and id.
             | Nullable because a security event concerns a user, not a resource,
             | and because a financial event is often best described against the
             | document that produced it (a sales invoice) even when the row that
             | changed was its journal. The journal is then named in metadata.
             */
            $table->string('auditable_type', 150)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            /*
             | The request/correlation identifier, so the HTTP request that caused
             | the action can be connected to the audit row. Assigned by
             | AssignRequestId when absent.
             */
            $table->string('request_id', 64)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            /*
             | The changed fields only, not a full model snapshot. See §19/§20 of
             | the brief: a practical policy is business identity fields, status
             | changes, amounts and references - not an enormous copy of every row.
             */
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();

            // A small, explicit context bag: journal id/number, source pointers,
            // a reason string where one exists. Never secrets.
            $table->json('metadata')->nullable();

            $table->timestamp('created_at');

            /*
             | The query shapes the audit API actually runs, in order:
             |
             |  1. (company_id, created_at)                 - the default listing,
             |                                                newest first.
             |  2. (company_id, action, created_at)         - filtered by action.
             |  3. (company_id, auditable_type, auditable_id)
             |                                             - "the history of this
             |                                                resource".
             |  4. (company_id, actor_id, created_at)       - "what did this user do".
             |  5. request_id                               - "what did this request
             |                                                touch", across
             |                                                companies because a
             |                                                request can only ever
             |                                                affect one, and the id
             |                                                is the join key.
             */
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'action', 'created_at']);
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
            $table->index(['company_id', 'actor_id', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
