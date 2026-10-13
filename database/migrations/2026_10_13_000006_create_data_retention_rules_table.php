<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `data_retention_rules`: what the `retention_policy` column on **every**
     * business object already points at (data-model §1.1). The rules land with the
     * schema and not with the retention UI for one reason: a `retention_policy`
     * value that resolves to nothing is a row the purge job skips forever, so
     * resolution has to be defined before the first writer exists.
     * `App\Security\Retention\RetentionRules` is that resolver — it reads this table
     * first, falls back to the shipped defaults, and throws
     * `UnknownRetentionRuleException` when a name resolves to neither.
     *
     * A rule is the whole answer, not a number of days:
     *  - `legal_basis` is the GDPR Article 6 basis the period rests on (§10.3
     *    "consent/legal basis notering"); `RetentionBasis` names the four this
     *    product can honestly claim.
     *  - `clock_column` is where the countdown starts (`occurred_at` for a log,
     *    `deleted_at` for a record with an undo window, `last_seen` for a device).
     *  - `purge_action` is `purge`, `anonymize`, or `keep_until_erased` — and
     *    `retain_days` is null **if and only if** the action is the last of those,
     *    an equivalence `RetentionRule` enforces, so no rule is clockless by
     *    omission.
     *  - `minimum_retain_days` is the floor the retention UI may not let a user go
     *    below, which is how the seven-year audit obligation survives a settings
     *    pane. It exists only on `user_adjustable` rules.
     *  - `applies_to` lists the tables the rule governs explicitly rather than by
     *    pattern, so the contract test can check both directions: every governed
     *    table has a rule, and every rule's clock column exists on its tables.
     *
     * `changed_by_user_id` and `audit_event_id` make an adjusted rule attributable
     * the same way a privacy-mode change is; `is_default` marks the rows `install()`
     * wrote, so "reset to the shipped rule" is a decidable operation.
     */
    public function up(): void
    {
        Schema::create('data_retention_rules', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('name', 64);
            $table->text('description');
            $table->string('legal_basis', 40);
            $table->string('clock_column', 40);
            $table->string('purge_action', 20);
            $table->unsignedInteger('retain_days')->nullable();
            $table->unsignedInteger('grace_days')->default(0);
            $table->unsignedInteger('minimum_retain_days')->nullable();
            $table->boolean('user_adjustable')->default(false);
            $table->json('applies_to');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->dateTime('effective_from');
            $table->char('changed_by_user_id', 26)->nullable();
            $table->char('audit_event_id', 26)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('changed_by_user_id', 'data_retention_rules_changed_by_user_fk')->references('id')->on('users');
            $table->foreign('audit_event_id', 'data_retention_rules_audit_event_fk')->references('id')->on('audit_events');
            $table->unique(['organization_id', 'name'], 'data_retention_rules_organization_name_unique');
            $table->index(['organization_id', 'is_active'], 'data_retention_rules_organization_active_index');
            $table->index(['organization_id', 'purge_action'], 'data_retention_rules_organization_action_index');
        });
    }
};
