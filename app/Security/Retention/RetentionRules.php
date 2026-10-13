<?php

declare(strict_types=1);

namespace App\Security\Retention;

use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shipped set of named retention rules, and the only way a `retention_policy`
 * value is turned into a rule.
 *
 * Why this lands with the schema (S2-03, #86) and not with the retention UI: every
 * business object in `docs/architecture/data-model.md` §1.1 already carries
 * `retention_policy` and `retain_until`. A value in that column that resolves to
 * nothing is a row the purge job skips forever — data kept by accident, which is
 * the one failure mode §10.3's "gallring" cannot tolerate. So resolution is defined
 * before the first writer exists, and it throws rather than returning null.
 *
 * Two layers, in this order:
 *
 *  1. **`data_retention_rules`** — the per-workspace rows. This is where an owner's
 *     adjustment lives (within `minimumRetainDays`), and where a workspace-specific
 *     rule the defaults never named may be added.
 *  2. **`defaults()`** — the shipped rules below, which every install has whether or
 *     not the table has been populated. `install()` copies them into the table.
 *
 * `tableRules()` maps every table that exists today to the rule that expires its
 * rows; `plannedTableRules()` does the same for the §3.1 tables whose own backlog
 * item creates them, so the inventory is complete now and the contract test stays
 * green in whichever order those items land.
 */
final class RetentionRules
{
    public const string TABLE = 'data_retention_rules';

    /**
     * Tables that deliberately carry no retention policy: framework tables, the
     * install marker and short-lived credential records with their own expiry
     * (`otp_codes` is pruned daily by `model:prune`, `sessions` by the session
     * driver, `passkey_credentials` and `auth_devices` live and die with the
     * account per docs/architecture/auth.md).
     *
     * @return list<string>
     */
    public static function ungovernedTables(): array
    {
        return [
            'auth_devices',
            'cache',
            'cache_locks',
            'failed_jobs',
            'install_state',
            'job_batches',
            'jobs',
            'migrations',
            'otp_codes',
            'passkey_credentials',
            'password_reset_tokens',
            'sessions',
            'users',
        ];
    }

    /** @return array<string, RetentionRule> keyed by rule name */
    public static function defaults(): array
    {
        $rules = [
            new RetentionRule(
                name: 'workspace_registry',
                description: 'The workspace and its memberships. No clock: the workspace exists while the install does, and leaves through an uninstall or an erasure request.',
                basis: RetentionBasis::Contract,
                clock: RetentionClock::DeletedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                appliesTo: ['organizations', 'memberships'],
            ),
            new RetentionRule(
                name: 'audit_log',
                description: 'The §10/§11 audit and access log. Seven years, because it is the record that an action was approved and by whom; a floor of one year keeps it useful after any adjustment.',
                basis: RetentionBasis::LegalObligation,
                clock: RetentionClock::OccurredAt,
                action: PurgeAction::Purge,
                retainDays: 2555,
                minimumRetainDays: 365,
                userAdjustable: true,
                appliesTo: ['audit_events'],
            ),
            new RetentionRule(
                name: 'governance_record',
                description: 'The privacy mode and retention rules themselves, including who changed them. No clock: this is the evidence that answers "when was Local Only turned off" (threat model T-68).',
                basis: RetentionBasis::LegalObligation,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                appliesTo: ['privacy_policies', 'data_retention_rules'],
            ),
            new RetentionRule(
                name: 'health_telemetry',
                description: 'Local health and WAL/checkpoint events (ADR-002 D6). Ninety days is enough to see a trend; none of it is customer content and none of it leaves the Mac.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::OccurredAt,
                action: PurgeAction::Purge,
                retainDays: 90,
                minimumRetainDays: 7,
                userAdjustable: true,
                appliesTo: ['health_events'],
            ),
            new RetentionRule(
                name: 'backup_log',
                description: 'The record of snapshot, restore-drill and profile-migration runs (ADR-002 D5/D10). One year, so a restore drill can be shown to have happened.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 365,
                minimumRetainDays: 30,
                userAdjustable: true,
                appliesTo: ['backup_runs'],
            ),
            new RetentionRule(
                name: 'brief_history',
                description: 'Frozen Executive Brief versions and their items. Two years of history with a thirty-day grace, so a deleted brief can be recovered.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 730,
                graceDays: 30,
                minimumRetainDays: 30,
                userAdjustable: true,
                appliesTo: ['briefs', 'brief_items'],
            ),
            new RetentionRule(
                name: 'delivery_receipts',
                description: 'The append-only brief delivery log. Seven years: it is the proof of what was sent to whom under the §2 send consent, and the index that makes a double send impossible to record.',
                basis: RetentionBasis::LegalObligation,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 2555,
                minimumRetainDays: 365,
                userAdjustable: true,
                appliesTo: ['brief_deliveries'],
            ),
            new RetentionRule(
                name: 'crm_records',
                description: 'Customers, projects, tasks and their links — the local CRM as system of record. No clock: a customer record lives as long as the relationship, and leaves when the owner deletes it, after a ninety-day undo window.',
                basis: RetentionBasis::Contract,
                clock: RetentionClock::DeletedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                graceDays: 90,
                appliesTo: ['customers', 'customer_contact', 'projects', 'project_links', 'project_contact', 'tasks', 'task_assignees'],
            ),
            new RetentionRule(
                name: 'contact_personal_data',
                description: 'Natural persons and their addresses (always sensitivity class `personal`). Three years without activity, then anonymised rather than purged, so the timeline keeps its shape while the person stops being identifiable (§10.3 data minimisation).',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::UpdatedAt,
                action: PurgeAction::Anonymize,
                retainDays: 1095,
                graceDays: 30,
                minimumRetainDays: 90,
                userAdjustable: true,
                appliesTo: ['contacts', 'contact_emails'],
            ),
            new RetentionRule(
                name: 'communication_timeline',
                description: 'The CRM projection of communications (ADR-002 D9). Three years from when the item occurred; purging a projection never touches the provider system of record.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::OccurredAt,
                action: PurgeAction::Purge,
                retainDays: 1095,
                graceDays: 30,
                minimumRetainDays: 30,
                userAdjustable: true,
                appliesTo: ['communications'],
            ),
            new RetentionRule(
                name: 'commitment_log',
                description: 'Promises extracted from a communication. Three years, matching the timeline they are read from; a kept commitment is still the answer to "what did we promise".',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 1095,
                graceDays: 30,
                minimumRetainDays: 30,
                userAdjustable: true,
                appliesTo: ['commitments'],
            ),
            new RetentionRule(
                name: 'provider_snapshots',
                description: 'Provider-agnostic snapshots of non-mail external items. One year: every row is re-derivable from the provider by cursor, so a short clock costs a re-sync and not data.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 365,
                minimumRetainDays: 30,
                userAdjustable: true,
                appliesTo: ['source_records'],
            ),
            new RetentionRule(
                name: 'connection_metadata',
                description: 'Connected mail and non-mail accounts: provider, identity, scopes and the Keychain reference — never a credential. No clock: a connection lives until it is disconnected, which deletes the row and its Keychain item after a thirty-day undo window.',
                basis: RetentionBasis::Contract,
                clock: RetentionClock::DeletedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                graceDays: 30,
                appliesTo: ['email_connections', 'external_accounts'],
            ),
            new RetentionRule(
                name: 'sync_state',
                description: 'Sync cursors. Deliberately clockless: expiring a cursor on a timer would silently trigger a full resync of every folder, so a cursor leaves only with its connection.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::UpdatedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                appliesTo: ['email_sync_cursors', 'sync_cursors'],
            ),
            new RetentionRule(
                name: 'push_devices',
                description: 'Opt-in Web Push subscriptions (ADR-006). Held on consent and dropped after 180 days without the device being seen, because a subscription nobody uses is a secret kept for nothing.',
                basis: RetentionBasis::Consent,
                clock: RetentionClock::LastSeen,
                action: PurgeAction::Purge,
                retainDays: 180,
                minimumRetainDays: 7,
                userAdjustable: true,
                appliesTo: ['push_subscriptions'],
            ),
            new RetentionRule(
                name: 'mcp_registry',
                description: 'MCP servers and their tool lists with pinned versions and schema hashes (ADR-005 D4.6). No clock: the pinned hash is what rug-pull detection compares against, so it may not expire while the server is connected.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::UpdatedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                appliesTo: ['mcp_servers', 'mcp_tools'],
            ),
            new RetentionRule(
                name: 'mcp_grant_record',
                description: 'Per-tool MCP grants, including revoked ones. No clock: a revoked grant is the record that it was once given, which is the question an incident asks first.',
                basis: RetentionBasis::LegalObligation,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                appliesTo: ['mcp_grants'],
            ),
            new RetentionRule(
                name: 'ai_run_log',
                description: 'AI runs, tool runs and browser sessions with their provenance (§10.1 item 2). One year; the content they summarise is governed by its own rule.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 365,
                minimumRetainDays: 7,
                userAdjustable: true,
                appliesTo: ['ai_runs', 'tool_runs', 'browser_sessions'],
            ),
            new RetentionRule(
                name: 'ai_provider_registry',
                description: 'The register of providers, models and their documented processing regions (§10.1 item 2; ADR-004 D5 evidence). No clock: the register is what an EU_RESTRICTED claim rests on.',
                basis: RetentionBasis::Contract,
                clock: RetentionClock::UpdatedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                appliesTo: ['ai_providers', 'ai_models'],
            ),
            new RetentionRule(
                name: 'approval_record',
                description: 'Frozen action proposals and the approvals that released them (§11). Seven years: the approver identity, the frozen input hash and the nonce are the evidence that an outbound action was authorised.',
                basis: RetentionBasis::LegalObligation,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 2555,
                minimumRetainDays: 365,
                userAdjustable: true,
                appliesTo: ['action_proposals', 'action_approvals'],
            ),
            new RetentionRule(
                name: 'memory_store',
                description: 'App-owned memories and their sources (§4). No clock: a memory is curated, not accumulated, and is removed by the annotated operation that created it.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::UpdatedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                graceDays: 30,
                appliesTo: ['memories', 'memory_sources'],
            ),
            new RetentionRule(
                name: 'email_store',
                description: 'The provider system of record for mail (ADR-002 D9). Three years from when the message was sent or received, with a thirty-day grace; re-syncing the provider restores anything purged early.',
                basis: RetentionBasis::LegitimateInterests,
                clock: RetentionClock::OccurredAt,
                action: PurgeAction::Purge,
                retainDays: 1095,
                graceDays: 30,
                minimumRetainDays: 30,
                userAdjustable: true,
                appliesTo: ['email_messages', 'email_threads', 'email_attachments'],
            ),
            new RetentionRule(
                name: 'email_account_state',
                description: 'Folder selection, per-account permissions and identity mappings (§5.6). No clock: this is the connection\'s configuration, not its content.',
                basis: RetentionBasis::Contract,
                clock: RetentionClock::UpdatedAt,
                action: PurgeAction::KeepUntilErased,
                retainDays: null,
                appliesTo: ['email_folders', 'email_account_permissions', 'email_identity_mappings'],
            ),
            new RetentionRule(
                name: 'email_send_log',
                description: 'Outgoing mail attempts from a connected account. Seven years, for the same reason as the brief delivery log: it is the proof of what was sent under an explicit approval.',
                basis: RetentionBasis::LegalObligation,
                clock: RetentionClock::CreatedAt,
                action: PurgeAction::Purge,
                retainDays: 2555,
                minimumRetainDays: 365,
                userAdjustable: true,
                appliesTo: ['email_delivery_attempts'],
            ),
        ];

        $keyed = [];

        foreach ($rules as $rule) {
            $keyed[$rule->name] = $rule;
        }

        return $keyed;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::defaults());
    }

    public static function has(string $name): bool
    {
        return array_key_exists($name, self::defaults());
    }

    /**
     * The shipped default, ignoring anything stored.
     *
     * @throws UnknownRetentionRuleException
     */
    public static function default(string $name): RetentionRule
    {
        $defaults = self::defaults();

        return $defaults[$name] ?? throw UnknownRetentionRuleException::named($name, array_keys($defaults));
    }

    /**
     * Resolve a `retention_policy` value: the stored rule for this workspace if
     * there is one, otherwise the shipped default. Throws when the name resolves to
     * neither — a dangling policy name is never a row that quietly never expires.
     *
     * @throws UnknownRetentionRuleException
     */
    public static function resolve(string $name, ?string $organizationId = null): RetentionRule
    {
        $stored = self::stored($name, $organizationId);

        if ($stored instanceof RetentionRule) {
            return $stored;
        }

        return self::default($name);
    }

    /**
     * The rule governing one table's rows.
     *
     * @throws UnknownRetentionRuleException
     */
    public static function forTable(string $table, ?string $organizationId = null): RetentionRule
    {
        $map = self::allTableRules();

        if (! array_key_exists($table, $map)) {
            throw UnknownRetentionRuleException::forTable($table);
        }

        return self::resolve($map[$table], $organizationId);
    }

    /**
     * Table → rule name for every governed table that exists today.
     *
     * @return array<string, string>
     */
    public static function tableRules(): array
    {
        return [
            'organizations' => 'workspace_registry',
            'memberships' => 'workspace_registry',
            'audit_events' => 'audit_log',
            'privacy_policies' => 'governance_record',
            'data_retention_rules' => 'governance_record',
            'health_events' => 'health_telemetry',
            'backup_runs' => 'backup_log',
            'briefs' => 'brief_history',
            'brief_items' => 'brief_history',
            'brief_deliveries' => 'delivery_receipts',
            'customers' => 'crm_records',
            'customer_contact' => 'crm_records',
            'projects' => 'crm_records',
            'project_links' => 'crm_records',
            'project_contact' => 'crm_records',
            'tasks' => 'crm_records',
            'task_assignees' => 'crm_records',
            'contacts' => 'contact_personal_data',
            'contact_emails' => 'contact_personal_data',
            'communications' => 'communication_timeline',
            'commitments' => 'commitment_log',
            'source_records' => 'provider_snapshots',
            'email_connections' => 'connection_metadata',
            'external_accounts' => 'connection_metadata',
            'email_sync_cursors' => 'sync_state',
            'sync_cursors' => 'sync_state',
            'push_subscriptions' => 'push_devices',
            'mcp_servers' => 'mcp_registry',
            'mcp_tools' => 'mcp_registry',
            'mcp_grants' => 'mcp_grant_record',
            'ai_providers' => 'ai_provider_registry',
            'ai_models' => 'ai_provider_registry',
            'ai_runs' => 'ai_run_log',
            'tool_runs' => 'ai_run_log',
            'browser_sessions' => 'ai_run_log',
            'action_proposals' => 'approval_record',
            'action_approvals' => 'approval_record',
            'memories' => 'memory_store',
            'memory_sources' => 'memory_store',
        ];
    }

    /**
     * Table → rule name for the §3.1 tables that land with their own backlog item —
     * today that is E3-2's mail store. Declared here so the inventory is complete
     * the moment those tables appear rather than when somebody remembers them, and
     * so the governance contract test passes whichever item merges first: a planned
     * table is exempt from the "the table exists" assertion but its rule must still
     * resolve.
     *
     * S2-02's nine AI, approval and memory tables were listed here while #85 and
     * #86 were open in parallel; they merged first (#114) and moved up into
     * `tableRules()`.
     *
     * @return array<string, string>
     */
    public static function plannedTableRules(): array
    {
        return [
            'email_messages' => 'email_store',
            'email_threads' => 'email_store',
            'email_attachments' => 'email_store',
            'email_folders' => 'email_account_state',
            'email_account_permissions' => 'email_account_state',
            'email_identity_mappings' => 'email_account_state',
            'email_delivery_attempts' => 'email_send_log',
        ];
    }

    /** @return array<string, string> */
    public static function allTableRules(): array
    {
        return [...self::tableRules(), ...self::plannedTableRules()];
    }

    /**
     * Copy the shipped defaults into `data_retention_rules` for one workspace,
     * idempotently: a rule the owner has already adjusted keeps its numbers, and a
     * new shipped rule is added. Returns how many rows were inserted.
     */
    public static function install(string $organizationId, string $actor = 'job:InstallRetentionRules'): int
    {
        $existing = DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->pluck('name')
            ->all();

        $now = CarbonImmutable::now('UTC');
        $provenance = json_encode(
            Provenance::job('InstallRetentionRules', ProvenanceOrigin::Import),
            JSON_THROW_ON_ERROR,
        );
        $rows = [];

        foreach (self::defaults() as $rule) {
            if (in_array($rule->name, $existing, true)) {
                continue;
            }

            $rows[] = [
                ...$rule->toRow(),
                'applies_to' => json_encode($rule->appliesTo, JSON_THROW_ON_ERROR),
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'is_active' => true,
                'is_default' => true,
                'effective_from' => $now,
                'changed_by_user_id' => null,
                'audit_event_id' => null,
                'source_id' => $actor,
                'sensitivity_class' => 'internal',
                'provenance' => $provenance,
                'retention_policy' => 'governance_record',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        DB::table(self::TABLE)->insert($rows);

        return count($rows);
    }

    private static function stored(string $name, ?string $organizationId): ?RetentionRule
    {
        if ($organizationId === null) {
            return null;
        }

        $row = DB::table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('name', $name)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = (array) $row;

        return RetentionRule::fromRow($attributes);
    }
}
