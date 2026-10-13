<?php

use App\AI\Routing\PrivacyMode;
use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §10.1 `privacy_policies`: where `LOCAL_ONLY`, `EU_RESTRICTED` and `CUSTOM`
     * actually live, with the `LOCAL_ONLY_ALLOW_LAN` toggle, the user who changed
     * them and the `audit_events` row that recorded the change. This is the
     * **storage half of OPEN-QUESTIONS A18**; S2-14 (#96) lands the rule and amends
     * ADR-004 with the rest of the answer (which transitions need the S2-05
     * re-authentication, what the UI re-confirms, the named audit event).
     *
     * `mode` stores `App\AI\Routing\PrivacyMode::configValue()` — the §10.1 spelling
     * `config('lea.privacy_mode')` carries and `/health/ready` reports, so the stored
     * policy and the surfaces use one vocabulary. `PrivacyMode::fromConfigValue()`
     * reads it back and falls closed to `LOCAL_ONLY` for anything unrecognised.
     *
     * Threat **T-68** is "the mode is turned down without the owner noticing", and
     * ADR-001 D4 has already removed the `.env` that would otherwise be the place to
     * do it. The shape here is what makes the remaining path visible:
     *
     *  - **Append-only.** One row per change, never updated in place and never soft
     *    deleted; the previous row is stamped `superseded_at` and kept, so the
     *    history of who widened the boundary and when survives.
     *  - **Exactly one active policy per workspace.** `active_marker` is a generated
     *    column that is 1 while `superseded_at` is null and NULL afterwards, and
     *    `privacy_policies_organization_active_unique` covers
     *    `(organization_id, active_marker)`. Both engines treat NULLs in a unique
     *    index as distinct, so any number of superseded rows may coexist with at
     *    most one live policy — the portable form of a partial unique index, the
     *    same device `brief_deliveries` uses for "exactly one sent".
     *  - **Attribution is a column, not a convention.** `changed_by_user_id` and
     *    `audit_event_id` are foreign keys; `reauthenticated_at` is where S2-14
     *    records that fresh proof of presence was taken before a widening change.
     *    The install-time default row has neither a user nor a proof and says so
     *    through `source = install_default`.
     */
    public function up(): void
    {
        Schema::create('privacy_policies', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('mode', 20)->default(PrivacyMode::LocalOnly->configValue());
            $table->boolean('allow_lan')->default(false);
            $table->string('source', 30);
            $table->string('reason', 200)->nullable();
            $table->char('changed_by_user_id', 26)->nullable();
            $table->char('audit_event_id', 26)->nullable();
            $table->dateTime('reauthenticated_at')->nullable();
            $table->dateTime('effective_from');
            $table->dateTime('superseded_at')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()
                ->virtualAs('case when superseded_at is null then 1 else null end');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('changed_by_user_id', 'privacy_policies_changed_by_user_fk')->references('id')->on('users');
            $table->foreign('audit_event_id', 'privacy_policies_audit_event_fk')->references('id')->on('audit_events');
            $table->unique(['organization_id', 'active_marker'], 'privacy_policies_organization_active_unique');
            $table->index(['organization_id', 'effective_from'], 'privacy_policies_organization_effective_from_index');
            $table->index(['organization_id', 'mode'], 'privacy_policies_organization_mode_index');
        });
    }
};
