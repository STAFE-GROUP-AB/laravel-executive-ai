<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One human approval of one frozen proposal (§3.1 `action_approvals`; ADR-005
     * D1.6 "a matching `action_approvals` row (approver identity, time)"; §11
     * approver identity, expiry, nonce, idempotency, fail closed). Append-only: no
     * soft delete, and a row is never updated except to stamp `consumed_at`.
     *
     * **Two unique indexes make a replayed approval unrecordable, not merely
     * detectable** — the argument ADR-009 D1.2 makes for `brief_deliveries`, that the
     * guarantee has to be a unique index and not an application check:
     *
     *  1. `action_approvals_proposal_unique (action_proposal_id)` — a proposal can be
     *     approved exactly once. A second approval of the same proposal, from a
     *     double-click, a retried request or a stolen screen, cannot be inserted.
     *  2. `action_approvals_nonce_unique (nonce)` — the single-use token from the
     *     proposal can be spent exactly once, across all proposals. An approval
     *     replayed with a captured nonce cannot be inserted either, even if the
     *     attacker also supplies a fresh proposal id.
     *
     * `proposal_hash` is copied from the proposal at approval time, so the executing
     * provider can recompute the hash of the action it is about to run and compare it
     * against what the approver actually saw — the content and the consent are bound
     * together without a second read of a mutable row.
     *
     * The approval is only ever recorded from an authenticated, re-authenticated UI
     * action (§11, ADR-005 D1.6): `reauth_at`, `auth_device_id`, `ip_address` and
     * `user_agent` are that evidence. `consumed_at` is stamped when the nonce is spent
     * by the connector or the automation provider, so an expired-but-unused approval
     * and a used one are distinguishable after the fact.
     */
    public function up(): void
    {
        Schema::create('action_approvals', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->char('action_proposal_id', 26);
            $table->string('nonce', 64);
            $table->string('proposal_hash', 71);

            // ADR-005 D1.6: approver identity and time.
            $table->char('approver_user_id', 26);
            $table->dateTime('approved_at');
            $table->dateTime('expires_at');

            // §11 re-auth evidence for a critical action.
            $table->dateTime('reauth_at')->nullable();
            $table->char('auth_device_id', 26)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->dateTime('consumed_at')->nullable();

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('action_proposal_id', 'action_approvals_proposal_fk')->references('id')->on('action_proposals');
            $table->foreign('approver_user_id', 'action_approvals_approver_user_fk')->references('id')->on('users');
            $table->foreign('auth_device_id', 'action_approvals_auth_device_fk')->references('id')->on('auth_devices');

            $table->unique(['action_proposal_id'], 'action_approvals_proposal_unique');
            $table->unique(['nonce'], 'action_approvals_nonce_unique');
            $table->index(['organization_id', 'approved_at'], 'action_approvals_organization_approved_at_index');
            $table->index(['approver_user_id', 'approved_at'], 'action_approvals_approver_approved_at_index');
        });
    }
};
