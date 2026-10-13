<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One user-visible automation session and the **session grant** behind it
     * (§3.1 `browser_sessions`, ADR-005 D1). The grant covers `observe()` within the
     * allowlist; every `execute()` still needs its own `action_proposals` row and
     * matching approval (D1.6). The columns mirror
     * `App\Browser\ComputerUse\AutomationSession` field for field, so the DTO can be
     * rehydrated from a row without a second lookup.
     *
     * A grant is only ever created from an authenticated, re-authenticated UI action
     * (§11, D1.6), never from a prompt or a tool result — `reauth_at` and
     * `auth_device_id` record which sign-in it came from, so an approval trail exists
     * for the grant itself and not only for the actions under it.
     *
     * Bounds are stored, not assumed: 60 minutes hard (`expires_at`), 15 minutes idle
     * (`idle_timeout_seconds` against `last_activity_at`), at most 25 actions
     * (`max_actions`) per D1.8. `allowlist` is D1.5's list of
     * `{bundle_id, window_title_pattern}`; the hard denylist (login window, System
     * Settings, password managers, terminals, `AXSecureTextField`) is enforced in code
     * and cannot be put in this column.
     *
     * No screenshot ever reaches this table — redacted frames live in `Caches/` for
     * the session's lifetime and are never written to the database, logs or the audit
     * trail (ADR-005 D1.7).
     */
    public function up(): void
    {
        Schema::create('browser_sessions', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->char('user_id', 26);
            $table->char('grant_id', 26);
            $table->string('state', 20);
            $table->json('allowlist');

            $table->dateTime('started_at');
            $table->dateTime('expires_at');
            $table->unsignedInteger('idle_timeout_seconds')->default(900);
            $table->dateTime('last_activity_at')->nullable();
            $table->unsignedSmallInteger('max_actions')->default(25);
            $table->unsignedSmallInteger('actions_taken')->default(0);

            // The re-authenticated UI action the grant came from (§11, ADR-005 D1.6).
            $table->dateTime('reauth_at')->nullable();
            $table->char('auth_device_id', 26)->nullable();

            $table->dateTime('ended_at')->nullable();
            $table->string('end_reason', 40)->nullable();
            $table->dateTime('panic_stopped_at')->nullable();

            // What performed the automation, for the D1.1 pin and incident review.
            $table->string('helper_version', 50)->nullable();
            $table->string('peekaboo_version', 50)->nullable();

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('user_id', 'browser_sessions_user_fk')->references('id')->on('users');
            $table->foreign('auth_device_id', 'browser_sessions_auth_device_fk')->references('id')->on('auth_devices');

            $table->unique(['grant_id'], 'browser_sessions_grant_id_unique');
            $table->index(['organization_id', 'state'], 'browser_sessions_organization_state_index');
            $table->index(['organization_id', 'expires_at'], 'browser_sessions_organization_expires_at_index');
            $table->index(['user_id', 'started_at'], 'browser_sessions_user_started_at_index');
        });
    }
};
