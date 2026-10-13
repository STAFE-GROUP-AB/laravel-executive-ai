<?php

declare(strict_types=1);

use App\AI\Routing\PrivacyMode;
use App\Integrations\Mail\Dto\SyncCursor;
use App\Models\Organization;
use App\Models\User;
use App\Security\Retention\RetentionRules;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BackendProfile;

uses(DatabaseTransactions::class);

/*
 * S2-03 (#86): the governance, retention and operations tables on the Sprint 1 and
 * S2-01 conventions, asserted on both ADR-002 D1 profiles (`migrations` group,
 * ADR-002 D10).
 */

$governanceTables = [
    'external_accounts',
    'sync_cursors',
    'email_connections',
    'email_sync_cursors',
    'privacy_policies',
    'data_retention_rules',
    'backup_runs',
    'health_events',
    'push_subscriptions',
    'mcp_servers',
    'mcp_tools',
    'mcp_grants',
];

function governanceProvenance(): string
{
    return json_encode(Provenance::job('GovernanceSchemaContractTest', ProvenanceOrigin::Manual), JSON_THROW_ON_ERROR);
}

/** @return array{0: string, 1: string} the organization and owner ids */
function governanceTenancy(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    return [(string) $organization->id, (string) $user->id];
}

function governanceConnection(string $organizationId, string $userId, string $address = 'owner@example.se'): string
{
    $id = (string) Str::ulid();

    DB::table('email_connections')->insert([
        'id' => $id,
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'provider' => 'google',
        'authorization_method' => 'oauth_pkce',
        'email_address' => $address,
        'account_label' => "{$address} (Google Workspace)",
        'granted_scopes' => '["https://www.googleapis.com/auth/gmail.readonly"]',
        'capabilities' => '["read","incremental_sync"]',
        'send_as_addresses' => '[]',
        'synced_folder_ids' => '[]',
        'status' => 'connected',
        'health_state' => 'healthy',
        'secret_service' => 'se.stafe.executive-ai.email',
        'secret_account' => $id,
        'secret_version' => 1,
        'connected_at' => '2026-10-13 06:00:00',
        'sensitivity_class' => 'personal',
        'provenance' => governanceProvenance(),
    ]);

    return $id;
}

it('creates every S2-03 governance, retention and operations table', function () use ($governanceTables): void {
    foreach ($governanceTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table}");
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('carries the BusinessObjectColumns set on every governance row', function () use ($governanceTables): void {
    foreach ($governanceTables as $table) {
        expect(Schema::hasColumns($table, ['id', 'organization_id', 'source_id', 'source_uri', 'sensitivity_class', 'provenance', 'retention_policy', 'retain_until', 'created_at', 'updated_at']))
            ->toBeTrue("§3.1 common columns on {$table}");
    }

    // Append-only logs and the policy history get no soft delete (data-model §1):
    // a superseded privacy policy is stamped, never deleted.
    foreach (['privacy_policies', 'backup_runs', 'health_events'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeFalse("{$table} is append-only");
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('keeps every identity column a fixed-width ULID column', function () use ($governanceTables): void {
    $idLike = ['id', 'organization_id', 'user_id', 'connection_id', 'external_account_id', 'server_id', 'tool_id', 'changed_by_user_id', 'granted_by_user_id', 'approved_by_user_id', 'reviewed_by_user_id', 'audit_event_id'];

    // `$table->char($c, 26)` is `char(26)` on MySQL and `varchar` on SQLite, whose
    // grammar has no CHAR type — the portable declaration is the same one (S2-01).
    $expected = BackendProfile::isSqlite() ? 'varchar' : 'char(26)';

    foreach ($governanceTables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            if (! in_array($column['name'], $idLike, true)) {
                continue;
            }

            expect(mb_strtolower((string) $column['type']))->toBe($expected, "{$table}.{$column['name']}");
        }
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('uses string columns instead of enum columns and UTC datetimes instead of timestamps', function () use ($governanceTables): void {
    foreach ($governanceTables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            $type = mb_strtolower((string) $column['type']);

            expect($type)->not->toStartWith('enum', "{$table}.{$column['name']} must be string + PHP enum (ADR-002 D10)")
                ->and($type)->not->toStartWith('timestamp', "{$table}.{$column['name']} must be a UTC datetime (ADR-002 D10)");
        }
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('names every governance index and foreign key explicitly', function () use ($governanceTables): void {
    foreach ($governanceTables as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            $name = (string) $index['name'];

            // Neither engine keeps the `<table>_pk` name the migration asks for:
            // SQLite calls it `sqlite_autoindex_<table>_1`, MySQL calls it `primary`.
            if (str_starts_with($name, 'sqlite_autoindex')) {
                continue;
            }

            expect($name)->toMatch('/^(primary|[a-z0-9_]+_(unique|index|fk))$/', "index {$name} on {$table}");
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['name'] === null) {
                continue;
            }

            expect((string) $foreignKey['name'])->toEndWith('_fk', "foreign key on {$table}");
        }
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('carries the indexes the governance queries are written against', function (string $table, array $expected): void {
    $names = collect(Schema::getIndexes($table))->pluck('name')->all();

    foreach ($expected as $index) {
        expect($names)->toContain($index);
    }
})->with([
    ['external_accounts', ['external_accounts_provider_account_unique', 'external_accounts_organization_status_index', 'external_accounts_user_provider_index']],
    ['sync_cursors', ['sync_cursors_account_scope_unique', 'sync_cursors_organization_resync_index']],
    ['email_connections', ['email_connections_provider_address_unique', 'email_connections_organization_status_index', 'email_connections_user_health_index']],
    ['email_sync_cursors', ['email_sync_cursors_connection_scope_unique', 'email_sync_cursors_organization_resync_index']],
    ['privacy_policies', ['privacy_policies_organization_active_unique', 'privacy_policies_organization_effective_from_index', 'privacy_policies_organization_mode_index']],
    ['data_retention_rules', ['data_retention_rules_organization_name_unique', 'data_retention_rules_organization_active_index', 'data_retention_rules_organization_action_index']],
    ['backup_runs', ['backup_runs_kind_started_at_index', 'backup_runs_status_started_at_index', 'backup_runs_retained_until_index']],
    ['health_events', ['health_events_name_occurred_at_index', 'health_events_status_occurred_at_index', 'health_events_category_occurred_at_index']],
    ['push_subscriptions', ['push_subscriptions_endpoint_unique', 'push_subscriptions_user_active_index', 'push_subscriptions_last_seen_index']],
    ['mcp_servers', ['mcp_servers_organization_name_unique', 'mcp_servers_organization_status_index', 'mcp_servers_organization_trust_tier_index']],
    ['mcp_tools', ['mcp_tools_server_tool_unique', 'mcp_tools_organization_review_state_index', 'mcp_tools_server_schema_hash_index']],
    ['mcp_grants', ['mcp_grants_tool_active_unique', 'mcp_grants_organization_server_index', 'mcp_grants_server_revoked_index']],
])->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('lands the mail tables under exactly the ADR-002 D8 names', function (): void {
    expect(Schema::hasTable('email_connections'))->toBeTrue()
        ->and(Schema::hasTable('email_sync_cursors'))->toBeTrue()
        // A4 is resolved: §5.6's security paragraph names the same table as D8.
        ->and(Schema::hasTable('connected_email_accounts'))->toBeFalse('ADR-002 D8: the table is email_connections')
        ->and(Schema::hasTable('email_account_sync_cursors'))->toBeFalse()
        // E3-2 owns the message store; S2-03 does not pre-empt it (ADR-002 D9.2).
        ->and(Schema::hasTable('email_messages'))->toBeFalse('email_messages lands with E3-2')
        ->and(Schema::hasTable('email_threads'))->toBeFalse('email_threads lands with E3-2');
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('holds a Keychain reference and never a credential on a connection row', function (string $table): void {
    expect(Schema::hasColumns($table, ['secret_service', 'secret_account', 'secret_version']))->toBeTrue("ADR-003 D1 reference triple on {$table}");

    $forbidden = '/(^|_)(token|password|passphrase|api_key|client_secret|refresh|verifier|credential|private_key)($|_)/';

    foreach (Schema::getColumns($table) as $column) {
        expect((string) $column['name'])->not->toMatch($forbidden, "ADR-003 D1: {$table}.{$column['name']} looks like a credential column");
    }
})->with(['email_connections', 'external_accounts', 'mcp_servers'])
    ->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('persists the §5.6 connection shape the EmailConnection DTO reads', function (): void {
    expect(Schema::hasColumns('email_connections', [
        'user_id', 'provider', 'authorization_method', 'email_address', 'display_name', 'account_label',
        'granted_scopes', 'capabilities', 'send_as_addresses', 'synced_folder_ids', 'server_settings',
        'read_only', 'send_enabled', 'status', 'health_state', 'sync_interval_minutes',
        'connected_at', 'last_verified_at', 'last_synced_at', 'reauthorization_required_at', 'revoked_at',
    ]))->toBeTrue();
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('keeps one email sync cursor per connection and scope', function (): void {
    [$organizationId, $userId] = governanceTenancy();
    $connectionId = governanceConnection($organizationId, $userId);

    $row = [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'connection_id' => $connectionId,
        'scope' => 'INBOX',
        'kind' => 'imap.qresync',
        'value' => '{"kind":"imap.qresync","scope":"INBOX","value":"1:42:7","version":1}',
        'value_bytes' => 68,
        'sensitivity_class' => 'internal',
        'provenance' => governanceProvenance(),
    ];

    DB::table('email_sync_cursors')->insert($row);

    expect(fn () => DB::table('email_sync_cursors')->insert([...$row, 'id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);

    // A second scope on the same connection is fine: Graph delta state is per folder.
    DB::table('email_sync_cursors')->insert([...$row, 'id' => (string) Str::ulid(), 'scope' => 'Archive']);

    expect(DB::table('email_sync_cursors')->where('connection_id', $connectionId)->count())->toBe(2);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('sizes a cursor value for SyncCursor::encode() at the 16 KiB cap plus envelope', function (): void {
    [$organizationId, $userId] = governanceTenancy();
    $connectionId = governanceConnection($organizationId, $userId);

    $encoded = (new SyncCursor(
        kind: 'graph.delta',
        scope: 'AAMkADk',
        value: str_repeat('a', SyncCursor::MAX_VALUE_LENGTH),
        parts: ['link' => 'delta'],
    ))->encode();

    expect(strlen($encoded))->toBeGreaterThan(SyncCursor::MAX_VALUE_LENGTH);

    DB::table('email_sync_cursors')->insert([
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'connection_id' => $connectionId,
        'scope' => 'AAMkADk',
        'kind' => 'graph.delta',
        'value' => $encoded,
        'value_bytes' => strlen($encoded),
        'sensitivity_class' => 'internal',
        'provenance' => governanceProvenance(),
    ]);

    $stored = (string) DB::table('email_sync_cursors')->where('connection_id', $connectionId)->value('value');

    expect($stored)->toBe($encoded)
        ->and(SyncCursor::decode($stored)->value)->toHaveLength(SyncCursor::MAX_VALUE_LENGTH);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('stores the A18 privacy mode, the LAN toggle, the changing user and the audit event', function (): void {
    expect(Schema::hasColumns('privacy_policies', ['mode', 'allow_lan', 'source', 'reason', 'changed_by_user_id', 'audit_event_id', 'reauthenticated_at', 'effective_from', 'superseded_at']))->toBeTrue();

    $foreignTables = collect(Schema::getForeignKeys('privacy_policies'))->pluck('foreign_table')->all();

    expect($foreignTables)->toContain('users')
        ->and($foreignTables)->toContain('audit_events');

    [$organizationId, $userId] = governanceTenancy();

    $auditEventId = (string) Str::ulid();
    DB::table('audit_events')->insert([
        'id' => $auditEventId,
        'organization_id' => $organizationId,
        'actor' => "user:{$userId}",
        'action' => 'privacy.mode.changed',
        'subject_type' => 'privacy_policy',
        'outcome' => 'ok',
        'occurred_at' => '2026-10-13 07:00:00',
        'sensitivity_class' => 'internal',
        'provenance' => governanceProvenance(),
    ]);

    foreach (PrivacyMode::cases() as $mode) {
        DB::table('privacy_policies')
            ->where('organization_id', $organizationId)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => '2026-10-13 07:00:00']);

        DB::table('privacy_policies')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'mode' => $mode->configValue(),
            'allow_lan' => $mode->supportsLanToggle(),
            'source' => 'owner',
            'reason' => 'contract test',
            'changed_by_user_id' => $userId,
            'audit_event_id' => $auditEventId,
            'reauthenticated_at' => '2026-10-13 07:00:00',
            'effective_from' => '2026-10-13 07:00:00',
            'sensitivity_class' => 'internal',
            'provenance' => governanceProvenance(),
        ]);
    }

    $active = DB::table('privacy_policies')
        ->where('organization_id', $organizationId)
        ->whereNull('superseded_at')
        ->first();

    expect($active)->not->toBeNull()
        ->and((string) $active->mode)->toBe(PrivacyMode::Custom->configValue())
        // The stored spelling is the one `lea.privacy_mode` and /health/ready use,
        // and it reads back through the same enum the AI adapters route on.
        ->and(PrivacyMode::fromConfigValue($active->mode))->toBe(PrivacyMode::Custom)
        ->and(PrivacyMode::Custom->wouldWiden(PrivacyMode::LocalOnly))->toBeTrue()
        ->and((bool) $active->allow_lan)->toBeFalse('the LAN toggle is only meaningful in LOCAL_ONLY');
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('allows exactly one active privacy policy per workspace', function (): void {
    [$organizationId] = governanceTenancy();

    $row = [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'mode' => PrivacyMode::LocalOnly->configValue(),
        'allow_lan' => false,
        'source' => 'install_default',
        'effective_from' => '2026-10-13 07:00:00',
        'sensitivity_class' => 'internal',
        'provenance' => governanceProvenance(),
    ];

    DB::table('privacy_policies')->insert($row);

    // The generated `active_marker` is 1 while `superseded_at` is null, so a second
    // live policy for the same workspace is rejected by the index rather than by a
    // check somebody has to remember to write (threat model T-68).
    expect(fn () => DB::table('privacy_policies')->insert([...$row, 'id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);

    DB::table('privacy_policies')->where('id', $row['id'])->update(['superseded_at' => '2026-10-13 08:00:00']);

    DB::table('privacy_policies')->insert([...$row, 'id' => (string) Str::ulid(), 'mode' => PrivacyMode::EuRestricted->configValue(), 'source' => 'owner']);

    expect(DB::table('privacy_policies')->where('organization_id', $organizationId)->count())->toBe(2);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('carries the ADR-005 D4.4 and D4.6 columns the rug-pull check compares against', function (): void {
    expect(Schema::hasColumns('mcp_servers', ['trust_tier', 'version', 'transport', 'status', 'tools_list_hash', 'tools_listed_at', 'executable_sha256', 'declared_egress', 'declared_fs_paths']))->toBeTrue()
        ->and(Schema::hasColumns('mcp_tools', ['server_id', 'tool_name', 'schema_hash', 'input_schema', 'annotations', 'is_mutating', 'review_state', 'first_seen_at', 'last_seen_at', 'removed_at']))->toBeTrue()
        ->and(Schema::hasColumns('mcp_grants', ['server_id', 'tool_id', 'granted_by_user_id', 'granted_schema_hash', 'requires_action_approval', 'granted_at', 'suspended_at', 'suspended_reason', 'revoked_at']))->toBeTrue();
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('keeps an MCP tool unique per server and name and at most one live grant per tool', function (): void {
    [$organizationId, $userId] = governanceTenancy();

    $serverId = (string) Str::ulid();
    DB::table('mcp_servers')->insert([
        'id' => $serverId,
        'organization_id' => $organizationId,
        'name' => 'executive',
        'display_name' => 'Executive AI tools',
        'trust_tier' => 'T0',
        'version' => '1.0.0',
        'transport' => 'stdio',
        'status' => 'connected',
        'tools_list_hash' => 'sha256:'.str_repeat('b', 64),
        'sensitivity_class' => 'internal',
        'provenance' => governanceProvenance(),
    ]);

    $tool = [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'server_id' => $serverId,
        'tool_name' => 'crm.search',
        'input_schema' => '{"type":"object"}',
        'schema_hash' => 'sha256:'.str_repeat('c', 64),
        'is_mutating' => false,
        'sends_crm_data' => true,
        'review_state' => 'reviewed',
        'first_seen_at' => '2026-10-13 07:00:00',
        'last_seen_at' => '2026-10-13 07:00:00',
        'sensitivity_class' => 'internal',
        'provenance' => governanceProvenance(),
    ];

    DB::table('mcp_tools')->insert($tool);

    expect(fn () => DB::table('mcp_tools')->insert([...$tool, 'id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);

    $grant = [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'server_id' => $serverId,
        'tool_id' => $tool['id'],
        'granted_by_user_id' => $userId,
        'granted_schema_hash' => $tool['schema_hash'],
        'trust_tier' => 'T0',
        'requires_action_approval' => false,
        'granted_at' => '2026-10-13 07:05:00',
        'sensitivity_class' => 'internal',
        'provenance' => governanceProvenance(),
    ];

    DB::table('mcp_grants')->insert($grant);

    expect(fn () => DB::table('mcp_grants')->insert([...$grant, 'id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);

    // Suspension is what D4.6 does when the tool list changes, and it frees the
    // slot for the grant the user gives after re-reviewing.
    DB::table('mcp_grants')->where('id', $grant['id'])->update([
        'suspended_at' => '2026-10-13 09:00:00',
        'suspended_reason' => 'tools/list changed',
    ]);

    DB::table('mcp_grants')->insert([...$grant, 'id' => (string) Str::ulid(), 'granted_schema_hash' => 'sha256:'.str_repeat('d', 64)]);

    expect(DB::table('mcp_grants')->where('tool_id', $tool['id'])->count())->toBe(2);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('keeps a push subscription unique on its endpoint with the ADR-002 D9.5 columns', function (): void {
    expect(Schema::hasColumns('push_subscriptions', ['endpoint', 'p256dh', 'auth', 'user_id', 'last_seen', 'revoked_at', 'created_at']))->toBeTrue();

    [$organizationId, $userId] = governanceTenancy();

    $row = [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'endpoint' => 'https://web.push.apple.com/'.str_repeat('e', 120),
        'p256dh' => str_repeat('f', 87),
        'auth' => str_repeat('g', 22),
        'device_label' => 'Chrome · Mac',
        'last_seen' => '2026-10-13 07:00:00',
        'sensitivity_class' => 'confidential',
        'provenance' => governanceProvenance(),
    ];

    DB::table('push_subscriptions')->insert($row);

    expect(fn () => DB::table('push_subscriptions')->insert([...$row, 'id' => (string) Str::ulid(), 'user_id' => $userId]))
        ->toThrow(QueryException::class);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('records the ADR-002 D5 backup steps and the D6 checkpoint events', function (): void {
    expect(Schema::hasColumns('backup_runs', ['kind', 'status', 'trigger', 'started_at', 'finished_at', 'snapshot_path', 'bytes', 'integrity_check', 'renamed_at', 'encrypted_at', 'checksum', 'row_counts', 'retained_until']))->toBeTrue()
        ->and(Schema::hasColumns('health_events', ['name', 'category', 'status', 'detail', 'context', 'duration_ms', 'occurred_at']))->toBeTrue();

    // Both are install-wide, not workspace-scoped: a snapshot covers the database
    // file and a locked database is a property of the install (as audit_events
    // allows for system events).
    $organizationId = collect(Schema::getColumns('backup_runs'))->firstWhere('name', 'organization_id');
    $healthOrganizationId = collect(Schema::getColumns('health_events'))->firstWhere('name', 'organization_id');

    expect($organizationId['nullable'])->toBeTrue()
        ->and($healthOrganizationId['nullable'])->toBeTrue();
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('governs every table that carries a retention policy, and governs no table that does not', function (): void {
    $governed = collect(Schema::getTables())
        ->pluck('name')
        ->filter(fn (string $table): bool => Schema::hasColumn($table, 'retention_policy'))
        ->sort()
        ->values();

    $mapped = RetentionRules::allTableRules();

    // 1. No dangling `retention_policy` column: every table that can write one has
    //    a rule to write. This is the check that fails when a new business table
    //    lands without a retention rule, which is the whole point of S2-03.
    foreach ($governed as $table) {
        expect(array_key_exists($table, $mapped))->toBeTrue("table {$table} carries retention_policy but no rule governs it");
    }

    // 2. No stale mapping: a table named in `tableRules()` exists and is a business
    //    object. `plannedTableRules()` is exempt — those tables land with their own item.
    foreach (RetentionRules::tableRules() as $table => $rule) {
        expect(Schema::hasTable($table))->toBeTrue("rule {$rule} names a table that does not exist: {$table}")
            ->and(Schema::hasColumn($table, 'retention_policy'))->toBeTrue("rule {$rule} governs {$table}, which is not a business object");
    }

    // 3. The "no retention policy by design" list is honest.
    foreach (RetentionRules::ungovernedTables() as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }

        expect(Schema::hasColumn($table, 'retention_policy'))->toBeFalse("{$table} is listed as ungoverned but carries retention_policy")
            ->and(array_key_exists($table, $mapped))->toBeFalse("{$table} is listed as ungoverned but a rule claims it");
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('gives every retention rule a clock column that exists on the tables it governs', function (): void {
    foreach (RetentionRules::defaults() as $rule) {
        foreach ($rule->appliesTo as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            expect(Schema::hasColumn($table, $rule->clock->column()))
                ->toBeTrue("retention rule {$rule->name} counts from {$rule->clock->column()}, which {$table} does not have");
        }
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');
