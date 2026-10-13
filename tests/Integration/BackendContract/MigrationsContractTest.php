<?php

declare(strict_types=1);

use App\Support\Health\Checks\DatabaseMigrationsCheck;
use App\Support\Health\CheckStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BackendProfile;

/*
 * ADR-002 D10 `migrations` group. CI runs the suite once per profile; the
 * `server` leg needs MySQL 8.4 and is skipped unless a MySQL connection is
 * actually configured, so a developer without MySQL stays green.
 */

function serverProfileWithoutMysql(): bool
{
    return BackendProfile::serverWithoutMysql();
}

it('has run every bundled migration with zero pending', function (): void {
    $outcome = app(DatabaseMigrationsCheck::class)->run();

    expect($outcome->status)->toBe(CheckStatus::Ok)
        ->and($outcome->detail)->toEndWith('; 0 pending');
})->group('backend-contract')->skip(fn (): bool => serverProfileWithoutMysql(), 'server profile selected but no MySQL connection configured');

/*
 * ADR-002 D10 as amended by S2-01: the group asserts `migrate` from zero with
 * zero pending, and nothing else. There is no rollback assertion because
 * migrations in this repository are up()-only — a `migrate:rollback` would be a
 * no-op at best, and `down()` on a shipped install is a data-loss operation.
 */
it('ships migrations that are up() only', function (): void {
    $files = glob(database_path('migrations/*.php')) ?: [];

    // Laravel's own bundled `cache` and `jobs` migrations keep the stock
    // `down()` they ship with; every migration this repository authors has none.
    $ours = array_values(array_filter($files, fn (string $file): bool => ! str_starts_with(basename($file), '0001_01_01_0000')));

    $withDown = array_values(array_map('basename', array_filter(
        $ours,
        fn (string $file): bool => str_contains((string) file_get_contents($file), 'function down('),
    )));

    expect($ours)->not->toBeEmpty()
        ->and($withDown)->toBe([]);
})->group('backend-contract');

it('creates the §3.1 tables of the Sprint 1 minimum with portable types', function (): void {
    foreach (['users', 'organizations', 'memberships', 'briefs', 'brief_items', 'brief_deliveries', 'audit_events', 'install_state', 'jobs', 'failed_jobs', 'cache', 'sessions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table}");
    }

    foreach (['organizations', 'memberships', 'briefs', 'brief_items', 'brief_deliveries', 'audit_events'] as $table) {
        expect(Schema::hasColumns($table, ['id', 'source_id', 'source_uri', 'sensitivity_class', 'provenance', 'retention_policy', 'retain_until', 'created_at', 'updated_at']))->toBeTrue("§3.1 columns on {$table}");
    }

    foreach (['memberships', 'briefs', 'brief_items', 'brief_deliveries', 'audit_events'] as $table) {
        expect(Schema::hasColumn($table, 'organization_id'))->toBeTrue("organization_id on {$table}");
    }

    foreach (['organizations', 'memberships', 'briefs', 'brief_items', 'users'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeTrue("soft delete on {$table}");
    }

    expect(Schema::hasColumn('users', 'password'))->toBeTrue()
        ->and(Schema::hasColumns('briefs', ['document_id', 'version', 'delivery_state', 'stale_after', 'idempotency_key', 'content_hash', 'schema_version', 'utc_offset']))->toBeTrue()
        ->and(Schema::hasColumns('brief_deliveries', ['idempotency_key', 'channel', 'recipient', 'attempt', 'status', 'message_id']))->toBeTrue();
})->group('backend-contract')->skip(fn (): bool => serverProfileWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('names every index and carries the brief idempotency indexes', function (): void {
    $indexes = collect(Schema::getIndexes('brief_deliveries'))->pluck('name');

    expect($indexes)->toContain('brief_deliveries_idempotency_attempt_unique')
        ->toContain('brief_deliveries_single_sent_unique')
        ->and(collect(Schema::getIndexes('briefs'))->pluck('name'))->toContain('briefs_organization_document_version_unique')
        ->toContain('briefs_idempotency_key_unique');
})->group('backend-contract')->skip(fn (): bool => serverProfileWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('runs SQLite in WAL mode on the local profile', function (): void {
    expect(DB::scalar('PRAGMA journal_mode'))->toBe('wal');
})->group('backend-contract')->skip(fn (): bool => config('database.default') !== 'sqlite', 'SQLite only');
