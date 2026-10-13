<?php

declare(strict_types=1);

use App\AI\Compliance\ProviderRegisterField;
use App\AI\Compliance\RegionEvidenceField;
use App\AI\Runs\RunOutcome;
use App\AI\Runs\RunProvenanceField;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BackendProfile;

uses(DatabaseTransactions::class);

/*
 * S2-02: the AI, approvals, tool-run and memory tables on the Sprint 1 conventions,
 * asserted on both ADR-002 D1 profiles (`migrations` group, ADR-002 D10).
 */

$aiTables = ['ai_providers', 'ai_models', 'ai_runs', 'tool_runs', 'action_proposals', 'action_approvals', 'memories', 'memory_sources', 'browser_sessions'];

/** Tables that are append-only logs and therefore carry no `deleted_at` (§1 "Soft delete"). */
$appendOnly = ['ai_runs', 'tool_runs', 'action_proposals', 'action_approvals', 'browser_sessions'];

$skip = fn (): bool => BackendProfile::serverWithoutMysql();
$reason = 'server profile selected but no MySQL connection configured';

$aiRunRow = /** @return array<string, mixed> */ function (string $organizationId): array {
    return [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'trace_id' => (string) Str::ulid(),
        'job_class' => 'ComposeBrief',
        'provider' => 'ollama',
        'model_id' => 'llama3.2:3b',
        'privacy_mode' => 'local_only',
        'prompt_hash' => 'sha256:'.str_repeat('a', 64),
        'capabilities_snapshot' => '{"text":true,"streaming":true}',
        'input_tokens' => 120,
        'output_tokens' => 340,
        'total_tokens' => 460,
        'cost_eur' => '0.000000',
        'wall_time_ms' => 4210,
        'started_at' => '2026-10-12 06:04:00',
        'outcome' => 'running',
        'dedupe_key' => 'sha256:'.str_repeat('b', 64),
        'dedupe_window_start' => '2026-10-12 06:00:00',
        'sensitivity_class' => 'internal',
        'provenance' => '{"origin":"ai_extraction","actor":"ai:ollama/llama3.2:3b","recorded_at":"2026-10-12T06:04:00+00:00","trace_id":null}',
    ];
};

$proposalRow = /** @return array<string, mixed> */ function (string $organizationId): array {
    return [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'kind' => 'ui_action',
        'status' => 'pending',
        'proposed_by' => 'ai:ollama/llama3.2:3b',
        'payload' => '{"kind":"click","element_id":"AXButton-1"}',
        'proposal_hash' => 'sha256:'.str_repeat('c', 64),
        'nonce' => bin2hex(random_bytes(16)),
        'idempotency_key' => (string) Str::ulid(),
        'frozen_at' => '2026-10-12 06:05:00',
        'expires_at' => '2026-10-12 06:20:00',
        'sensitivity_class' => 'internal',
        'provenance' => '{"origin":"ai_extraction","actor":"ai:ollama/llama3.2:3b","recorded_at":"2026-10-12T06:05:00+00:00","trace_id":null}',
    ];
};

$memoryRow = /** @return array<string, mixed> */ function (string $organizationId): array {
    return [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'scope' => 'organization',
        'scope_key' => 'organization',
        'kind' => 'preference',
        'memory_key' => 'brief.tone',
        'content' => 'Keep the brief short and name the source for every claim.',
        'status' => 'proposed',
        'written_by' => 'user:owner',
        'version' => 1,
        'sensitivity_class' => 'internal',
        'provenance' => '{"origin":"manual","actor":"user:owner","recorded_at":"2026-10-12T06:06:00+00:00","trace_id":null}',
    ];
};

it('creates every §3.1 AI, approvals and memory table', function () use ($aiTables): void {
    foreach ($aiTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table}");
    }
})->group('backend-contract')->skip($skip, $reason);

it('carries the BusinessObjectColumns set on every row', function () use ($aiTables, $appendOnly): void {
    foreach ($aiTables as $table) {
        expect(Schema::hasColumns($table, ['id', 'organization_id', 'source_id', 'source_uri', 'sensitivity_class', 'provenance', 'retention_policy', 'retain_until', 'created_at', 'updated_at']))
            ->toBeTrue("§3.1 common columns on {$table}");

        expect(Schema::hasColumn($table, 'deleted_at'))
            ->toBe(! in_array($table, $appendOnly, true), "soft delete on {$table}");
    }
})->group('backend-contract')->skip($skip, $reason);

it('keeps every identity column a fixed-width ULID column', function () use ($aiTables): void {
    $idLike = ['id', 'organization_id', 'user_id', 'trace_id', 'grant_id', 'idempotency_key', 'ai_provider_id', 'ai_model_id', 'ai_run_id', 'region_evidence_id', 'mcp_server_id', 'action_proposal_id', 'browser_session_id', 'memory_id', 'communication_id', 'subject_id', 'supersedes_id', 'auth_device_id', 'approver_user_id', 'requested_by_user_id', 'rejected_by_user_id', 'reviewed_by_user_id', 'verified_by_user_id', 'license_accepted_by_user_id'];

    // `$table->char($c, 26)` is `char(26)` on MySQL and `varchar` on SQLite, whose
    // grammar has no CHAR type — the portable declaration is the same one.
    $expected = BackendProfile::isSqlite() ? 'varchar' : 'char(26)';

    foreach ($aiTables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            if (! in_array($column['name'], $idLike, true)) {
                continue;
            }

            expect(mb_strtolower((string) $column['type']))->toBe($expected, "{$table}.{$column['name']}");
        }
    }
})->group('backend-contract')->skip($skip, $reason);

it('uses string columns instead of enum columns and UTC datetimes instead of timestamps', function () use ($aiTables): void {
    foreach ($aiTables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            $type = mb_strtolower((string) $column['type']);

            expect($type)->not->toStartWith('enum', "{$table}.{$column['name']} must be string + PHP enum (ADR-002 D10)")
                ->and($type)->not->toStartWith('timestamp', "{$table}.{$column['name']} must be a UTC datetime (ADR-002 D10)");
        }
    }
})->group('backend-contract')->skip($skip, $reason);

it('names every index and foreign key explicitly', function () use ($aiTables): void {
    foreach ($aiTables as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            $name = (string) $index['name'];

            if (str_starts_with($name, 'sqlite_autoindex')) {
                continue;
            }

            expect($name)->toMatch('/^(primary|[a-z0-9_]+_(unique|index|fulltext|fk))$/', "index {$name} on {$table}");
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['name'] === null) {
                continue;
            }

            expect((string) $foreignKey['name'])->toEndWith('_fk', "foreign key on {$table}");
        }
    }
})->group('backend-contract')->skip($skip, $reason);

it('carries the indexes the AI and approvals queries are written against', function (string $table, array $expected): void {
    $names = collect(Schema::getIndexes($table))->pluck('name')->all();

    foreach ($expected as $index) {
        expect($names)->toContain($index);
    }
})->with([
    ['ai_providers', ['ai_providers_organization_label_unique', 'ai_providers_secret_unique', 'ai_providers_organization_enabled_index', 'ai_providers_organization_region_index', 'ai_providers_organization_evidence_expiry_index']],
    ['ai_models', ['ai_models_provider_model_unique', 'ai_models_organization_enabled_index', 'ai_models_organization_capability_source_index', 'ai_models_provider_enabled_index']],
    ['ai_runs', ['ai_runs_dedupe_window_unique', 'ai_runs_trace_id_index', 'ai_runs_organization_started_at_index', 'ai_runs_organization_provider_started_at_index', 'ai_runs_organization_job_started_at_index', 'ai_runs_organization_outcome_index']],
    ['tool_runs', ['tool_runs_trace_id_index', 'tool_runs_ai_run_step_index', 'tool_runs_organization_started_at_index', 'tool_runs_organization_tool_started_at_index', 'tool_runs_organization_decision_index']],
    ['action_proposals', ['action_proposals_idempotency_key_unique', 'action_proposals_nonce_unique', 'action_proposals_proposal_hash_index', 'action_proposals_organization_status_index', 'action_proposals_organization_kind_status_index', 'action_proposals_session_frozen_at_index', 'action_proposals_trace_id_index']],
    ['action_approvals', ['action_approvals_proposal_unique', 'action_approvals_nonce_unique', 'action_approvals_organization_approved_at_index', 'action_approvals_approver_approved_at_index']],
    ['memories', ['memories_single_active_unique', 'memories_organization_status_kind_index', 'memories_organization_scope_index', 'memories_subject_index', 'memories_user_status_index', 'memories_organization_expires_at_index']],
    ['memory_sources', ['memory_sources_memory_position_unique', 'memory_sources_organization_source_index', 'memory_sources_communication_index', 'memory_sources_ai_run_index']],
    ['browser_sessions', ['browser_sessions_grant_id_unique', 'browser_sessions_organization_state_index', 'browser_sessions_organization_expires_at_index', 'browser_sessions_user_started_at_index']],
])->group('backend-contract')->skip($skip, $reason);

it('carries all thirteen ADR-004 D7 provenance fields on ai_runs', function (): void {
    expect(RunProvenanceField::cases())->toHaveCount(13);

    foreach (RunProvenanceField::cases() as $field) {
        expect(Schema::hasColumns('ai_runs', $field->columns()))
            ->toBeTrue("D7 provenance field {$field->value}");
    }
})->group('backend-contract')->skip($skip, $reason);

it('carries an outcome vocabulary that includes cancelled', function (): void {
    expect(array_map(fn (RunOutcome $outcome): string => $outcome->value, RunOutcome::cases()))
        ->toContain('cancelled')
        ->toContain('refused')
        ->toContain('succeeded');

    // The column is a portable string, not an engine enum (ADR-002 D10), so the
    // vocabulary lives in PHP and `cancelled` must be storable.
    expect(Schema::hasColumn('ai_runs', 'cancellation_source'))->toBeTrue();
})->group('backend-contract')->skip($skip, $reason);

it('carries ADR-004 D5\'s eight evidence fields on ai_providers', function (): void {
    expect(RegionEvidenceField::cases())->toHaveCount(8);

    foreach (RegionEvidenceField::cases() as $field) {
        expect(Schema::hasColumns('ai_providers', $field->columns()))
            ->toBeTrue("D5 evidence field {$field->value}");
    }
})->group('backend-contract')->skip($skip, $reason);

it('carries ADR-007 D6\'s R1-R7 register rows', function (): void {
    expect(ProviderRegisterField::cases())->toHaveCount(7);

    foreach (ProviderRegisterField::cases() as $field) {
        foreach ($field->columns() as $qualified) {
            [$table, $column] = explode('.', $qualified, 2);

            expect(Schema::hasColumn($table, $column))
                ->toBeTrue("D6 {$field->value} column {$qualified}");
        }
    }
})->group('backend-contract')->skip($skip, $reason);

it('stores only the Keychain coordinates of an AI provider credential', function (): void {
    // ADR-003 D1: `secret_service`, `secret_account` and `secret_version` are the
    // only credential-adjacent columns allowed, and no column may hold a key,
    // token, password or verifier — encrypted or not.
    expect(Schema::hasColumns('ai_providers', ['secret_service', 'secret_account', 'secret_version']))->toBeTrue();

    $forbidden = ['/(^|_)(api_)?key$/', '/(^|_)token$/', '/password/', '/secret_value/', '/client_secret/', '/code_verifier/', '/credential$/', '/(^|_)headers$/'];

    foreach (Schema::getColumns('ai_providers') as $column) {
        $name = (string) $column['name'];

        foreach ($forbidden as $pattern) {
            expect(preg_match($pattern, $name))->toBe(0, "ai_providers.{$name} looks like a credential column (ADR-003 D1)");
        }
    }
})->group('backend-contract')->skip($skip, $reason);

it('makes a replayed AI run with the same dedupe key and window unrecordable', function () use ($aiRunRow): void {
    $organization = Organization::factory()->create();
    $run = $aiRunRow((string) $organization->id);

    DB::table('ai_runs')->insert($run);

    expect(fn () => DB::table('ai_runs')->insert([
        ...$run,
        'id' => (string) Str::ulid(),
        'trace_id' => (string) Str::ulid(),
    ]))->toThrow(QueryException::class);
})->group('backend-contract')->skip($skip, $reason);

it('frees the dedupe slot for a failed, refused or cancelled run but never for two live ones', function () use ($aiRunRow): void {
    $organization = Organization::factory()->create();
    $base = $aiRunRow((string) $organization->id);

    // Any number of runs that did not hold the slot may coexist...
    foreach (['failed', 'refused', 'cancelled'] as $outcome) {
        DB::table('ai_runs')->insert([
            ...$base,
            'id' => (string) Str::ulid(),
            'trace_id' => (string) Str::ulid(),
            'outcome' => $outcome,
        ]);
    }

    // ...alongside exactly one that does.
    DB::table('ai_runs')->insert([...$base, 'outcome' => 'succeeded']);

    expect(fn () => DB::table('ai_runs')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'trace_id' => (string) Str::ulid(),
        'outcome' => 'running',
    ]))->toThrow(QueryException::class);

    expect(DB::table('ai_runs')->where('organization_id', $organization->id)->count())->toBe(4);
})->group('backend-contract')->skip($skip, $reason);

it('lets the same dedupe key insert again in the next window', function () use ($aiRunRow): void {
    $organization = Organization::factory()->create();
    $base = $aiRunRow((string) $organization->id);

    DB::table('ai_runs')->insert($base);
    DB::table('ai_runs')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'trace_id' => (string) Str::ulid(),
        'dedupe_window_start' => '2026-10-12 07:00:00',
        'started_at' => '2026-10-12 07:04:00',
    ]);

    expect(DB::table('ai_runs')->where('dedupe_key', $base['dedupe_key'])->count())->toBe(2);
})->group('backend-contract')->skip($skip, $reason);

it('makes a replayed approval unrecordable per proposal and per nonce', function () use ($proposalRow): void {
    $organization = Organization::factory()->create();
    $approver = User::factory()->create();

    $first = $proposalRow((string) $organization->id);
    $second = $proposalRow((string) $organization->id);

    DB::table('action_proposals')->insert([$first, $second]);

    $approval = [
        'id' => (string) Str::ulid(),
        'organization_id' => (string) $organization->id,
        'action_proposal_id' => $first['id'],
        'nonce' => $first['nonce'],
        'proposal_hash' => $first['proposal_hash'],
        'approver_user_id' => (string) $approver->id,
        'approved_at' => '2026-10-12 06:10:00',
        'expires_at' => '2026-10-12 06:20:00',
        'sensitivity_class' => 'internal',
        'provenance' => '{"origin":"manual","actor":"user:owner","recorded_at":"2026-10-12T06:10:00+00:00","trace_id":null}',
    ];

    DB::table('action_approvals')->insert($approval);

    // (1) The same proposal cannot be approved twice, even with a fresh nonce.
    expect(fn () => DB::table('action_approvals')->insert([
        ...$approval,
        'id' => (string) Str::ulid(),
        'nonce' => bin2hex(random_bytes(16)),
    ]))->toThrow(QueryException::class);

    // (2) A captured nonce cannot be spent again, even against another proposal.
    expect(fn () => DB::table('action_approvals')->insert([
        ...$approval,
        'id' => (string) Str::ulid(),
        'action_proposal_id' => $second['id'],
    ]))->toThrow(QueryException::class);
})->group('backend-contract')->skip($skip, $reason);

it('makes a second proposal with the same idempotency key or nonce unrecordable', function () use ($proposalRow): void {
    $organization = Organization::factory()->create();
    $proposal = $proposalRow((string) $organization->id);

    DB::table('action_proposals')->insert($proposal);

    expect(fn () => DB::table('action_proposals')->insert([
        ...$proposal,
        'id' => (string) Str::ulid(),
        'nonce' => bin2hex(random_bytes(16)),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('action_proposals')->insert([
        ...$proposal,
        'id' => (string) Str::ulid(),
        'idempotency_key' => (string) Str::ulid(),
    ]))->toThrow(QueryException::class);
})->group('backend-contract')->skip($skip, $reason);

it('carries the ADR-005 D1.6 proposal columns and the §11 freeze columns', function (): void {
    expect(Schema::hasColumns('action_proposals', [
        'app_bundle_id', 'window_title', 'element_role', 'element_label', 'action_type',
        'redacted_value', 'screenshot_sha256', 'proposal_hash', 'nonce', 'expires_at',
        'idempotency_key', 'frozen_at', 'payload',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('action_approvals', ['approver_user_id', 'approved_at', 'nonce', 'proposal_hash', 'expires_at', 'consumed_at']))->toBeTrue();
})->group('backend-contract')->skip($skip, $reason);

it('admits exactly one active memory per scope and key', function () use ($memoryRow): void {
    $organization = Organization::factory()->create();
    $base = $memoryRow((string) $organization->id);

    foreach (['proposed', 'superseded', 'rejected'] as $status) {
        DB::table('memories')->insert([...$base, 'id' => (string) Str::ulid(), 'status' => $status]);
    }

    DB::table('memories')->insert([...$base, 'status' => 'active']);

    expect(fn () => DB::table('memories')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'status' => 'active',
    ]))->toThrow(QueryException::class);

    // A different key under the same scope is a different memory.
    DB::table('memories')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'memory_key' => 'prefers.language',
        'status' => 'active',
    ]);

    expect(DB::table('memories')->where('organization_id', $organization->id)->count())->toBe(5);
})->group('backend-contract')->skip($skip, $reason);
