<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One proposed outbound action, frozen (§3.1 `action_proposals`; §11 "frozen
     * immutable proposal, input/recipient/hash, approver identity, expiry, nonce,
     * idempotency, fail closed"; ADR-005 D1.6). The model only ever writes rows
     * here; nothing in this table carries authority on its own.
     *
     * **Immutable after freeze.** `proposal_hash` is `sha256:` over the RFC 8785
     * canonical form of `payload` (`App\Support\Json\Jcs`, the rule the brief's
     * `content_hash` already uses), so any later edit of the frozen content is
     * detectable by recomputation — the executing provider recomputes it from the
     * action it is about to run and refuses on mismatch
     * (`App\Browser\ComputerUse\AuthorizedUiAction`,
     * `App\Integrations\Mail\Dto\ApprovedOutgoingEmail`). On top of the hash,
     * `App\Models\Approvals\ActionProposal` refuses to save a change to any frozen
     * column once `frozen_at` is set: only `status` and the timestamps that record
     * what happened to the proposal may move.
     *
     * **Three keys, three different jobs:**
     *
     *  - `idempotency_key` (unique) — the same intent proposed twice produces one
     *    row, so a retried job cannot create a second approvable proposal;
     *  - `nonce` (unique) — the single-use token an approval must quote, and the
     *    column `action_approvals.nonce` is unique against, which is what makes a
     *    replay unrecordable rather than merely detectable;
     *  - `proposal_hash` (indexed, not unique) — content identity; two proposals of
     *    identical content are legitimate and differ by nonce.
     *
     * **ADR-005 D1.6's target columns** (app, window title, element role and label,
     * action type, redacted value, screenshot hash) are filled for the `ui_action`
     * kind. `redacted_value` is what the UI shows the approver; the text actually
     * typed is never stored, only `value_hash` (D1.3, D1.10 "never typed text").
     * `screenshot_sha256` is a hash for correlation only — no binary column, no image
     * path, here or in `tool_runs` and `audit_events` (ADR-003 D7 row 8).
     *
     * `payload` is the kind's frozen content (recipients, subject and body hash for an
     * email; the CRM subject for a deletion) and passes the `Redactor` on write.
     */
    public function up(): void
    {
        Schema::create('action_proposals', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->string('kind', 40);
            $table->string('status', 20)->default('pending');

            // Where the proposal came from. `proposed_by` uses the provenance actor
            // grammar (`ai:<provider>/<model>`, `job:<JobName>`, `user:<ulid>`).
            $table->char('ai_run_id', 26)->nullable();
            $table->char('trace_id', 26)->nullable();
            $table->char('browser_session_id', 26)->nullable();
            $table->string('proposed_by', 200);
            $table->char('requested_by_user_id', 26)->nullable();

            // ADR-005 D1.6: the frozen target of a UI action.
            $table->string('app_bundle_id', 200)->nullable();
            $table->string('window_title', 500)->nullable();
            $table->string('element_role', 100)->nullable();
            $table->string('element_label', 500)->nullable();
            $table->string('action_type', 40)->nullable();
            $table->string('redacted_value', 500)->nullable();
            $table->string('value_hash', 71)->nullable();
            $table->string('screenshot_sha256', 71)->nullable();

            // The frozen payload and its canonical hash (§11 content hash).
            $table->json('payload');
            $table->string('proposal_hash', 71);

            // §11: nonce, expiry, idempotency.
            $table->string('nonce', 64);
            $table->char('idempotency_key', 26);
            $table->dateTime('frozen_at');
            $table->dateTime('expires_at');

            // What happened to it.
            $table->dateTime('executed_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->char('rejected_by_user_id', 26)->nullable();
            $table->string('rejection_reason', 200)->nullable();

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('ai_run_id', 'action_proposals_ai_run_fk')->references('id')->on('ai_runs');
            $table->foreign('browser_session_id', 'action_proposals_browser_session_fk')->references('id')->on('browser_sessions');
            $table->foreign('requested_by_user_id', 'action_proposals_requested_by_user_fk')->references('id')->on('users');
            $table->foreign('rejected_by_user_id', 'action_proposals_rejected_by_user_fk')->references('id')->on('users');

            $table->unique(['idempotency_key'], 'action_proposals_idempotency_key_unique');
            $table->unique(['nonce'], 'action_proposals_nonce_unique');
            $table->index(['proposal_hash'], 'action_proposals_proposal_hash_index');
            $table->index(['organization_id', 'status', 'expires_at'], 'action_proposals_organization_status_index');
            $table->index(['organization_id', 'kind', 'status'], 'action_proposals_organization_kind_status_index');
            $table->index(['browser_session_id', 'frozen_at'], 'action_proposals_session_frozen_at_index');
            $table->index(['trace_id'], 'action_proposals_trace_id_index');
        });
    }
};
