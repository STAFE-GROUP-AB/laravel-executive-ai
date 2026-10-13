<?php

declare(strict_types=1);

use App\CRM\Search\SearchableEntity;
use App\Support\Database\MysqlFullTextGrammar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BackendProfile;
use Tests\Support\CrmFixturePack;

uses(DatabaseTransactions::class);

/*
 * S2-01: the §3.1 CRM and source tables on the Sprint 1 conventions, asserted on
 * both ADR-002 D1 profiles (`migrations` group, ADR-002 D10).
 */

$crmTables = ['customers', 'contacts', 'contact_emails', 'customer_contact', 'projects', 'project_links', 'project_contact', 'tasks', 'task_assignees', 'communications', 'commitments', 'source_records'];

it('creates every §3.1 CRM and source table', function () use ($crmTables): void {
    foreach ($crmTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table}");
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('carries the BusinessObjectColumns set on every CRM row', function () use ($crmTables): void {
    foreach ($crmTables as $table) {
        expect(Schema::hasColumns($table, ['id', 'organization_id', 'source_id', 'source_uri', 'sensitivity_class', 'provenance', 'retention_policy', 'retain_until', 'created_at', 'updated_at', 'deleted_at']))
            ->toBeTrue("§3.1 common columns on {$table}");
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('keeps every identity column a fixed-width ULID column', function () use ($crmTables): void {
    $idLike = ['id', 'organization_id', 'customer_id', 'contact_id', 'project_id', 'task_id', 'user_id', 'owner_user_id', 'account_id', 'communication_id', 'follow_up_task_id', 'brief_item_id', 'external_account_id'];

    // `$table->char($c, 26)` is `char(26)` on MySQL and `varchar` on SQLite,
    // whose grammar has no CHAR type — the portable declaration is the same one.
    $expected = BackendProfile::isSqlite() ? 'varchar' : 'char(26)';

    foreach ($crmTables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            if (! in_array($column['name'], $idLike, true)) {
                continue;
            }

            expect(mb_strtolower((string) $column['type']))->toBe($expected, "{$table}.{$column['name']}");
        }
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('uses string columns instead of enum columns and UTC datetimes instead of timestamps', function () use ($crmTables): void {
    foreach ($crmTables as $table) {
        foreach (Schema::getColumns($table) as $column) {
            $type = mb_strtolower((string) $column['type']);

            expect($type)->not->toStartWith('enum', "{$table}.{$column['name']} must be string + PHP enum (ADR-002 D10)")
                ->and($type)->not->toStartWith('timestamp', "{$table}.{$column['name']} must be a UTC datetime (ADR-002 D10)");
        }
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('names every CRM index and foreign key explicitly', function () use ($crmTables): void {
    foreach ($crmTables as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            $name = (string) $index['name'];

            // Neither engine keeps the `<table>_pk` name the migration asks for:
            // SQLite calls the primary key's index `sqlite_autoindex_<table>_1`
            // and MySQL calls it `primary`. Every index the migrations create
            // themselves does carry its name, which is what keeps a schema diff
            // stable; `_fk` is in the list because MySQL backs each foreign key
            // with an index named after the constraint.
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
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('carries the indexes the §3.1 CRM queries are written against', function (string $table, array $expected): void {
    $names = collect(Schema::getIndexes($table))->pluck('name')->all();

    foreach ($expected as $index) {
        expect($names)->toContain($index);
    }
})->with([
    ['customers', ['customers_organization_status_index', 'customers_organization_display_name_index', 'customers_organization_registration_index']],
    ['contacts', ['contacts_organization_display_name_index', 'contacts_organization_name_index']],
    ['contact_emails', ['contact_emails_organization_address_unique', 'contact_emails_contact_position_unique', 'contact_emails_contact_primary_index']],
    ['customer_contact', ['customer_contact_customer_contact_unique', 'customer_contact_contact_index']],
    ['projects', ['projects_organization_status_index', 'projects_customer_status_index', 'projects_organization_due_on_index']],
    ['project_links', ['project_links_project_position_unique', 'project_links_project_kind_index']],
    ['project_contact', ['project_contact_project_contact_unique', 'project_contact_contact_index']],
    ['tasks', ['tasks_organization_status_due_at_index', 'tasks_organization_source_index', 'tasks_customer_status_index', 'tasks_project_status_index', 'tasks_contact_index']],
    ['task_assignees', ['task_assignees_task_user_unique', 'task_assignees_user_organization_index']],
    ['communications', ['communications_organization_occurred_at_index', 'communications_organization_source_index', 'communications_customer_occurred_at_index', 'communications_contact_occurred_at_index', 'communications_account_occurred_at_index', 'communications_organization_match_index']],
    ['commitments', ['commitments_organization_status_due_at_index', 'commitments_customer_status_index', 'commitments_communication_index', 'commitments_follow_up_task_index', 'commitments_organization_brief_item_index']],
    ['source_records', ['source_records_external_unique', 'source_records_organization_fetched_at_index', 'source_records_account_fetched_at_index']],
])->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('carries the explainable-match columns on communications and no foreign key to email_messages', function (): void {
    expect(Schema::hasColumns('communications', ['source_type', 'source_id', 'customer_id', 'contact_id', 'match_reason', 'matched_by', 'confidence', 'correction_of']))->toBeTrue();

    $referenced = collect(Schema::getForeignKeys('communications'))->pluck('foreign_table')->all();

    expect($referenced)->not->toContain('email_messages')
        ->and($referenced)->not->toContain('email_threads')
        ->and($referenced)->not->toContain('email_connections')
        ->and(Schema::hasTable('email_messages'))->toBeFalse('email_messages lands with E3-2 (ADR-002 D9.2)')
        ->and(Schema::hasTable('messages'))->toBeFalse('§3.1 messages is email_messages (ADR-002 D9)')
        ->and(Schema::hasTable('threads'))->toBeFalse('§3.1 threads is email_threads (ADR-002 D9)');
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('carries the commitments follow-up and brief item columns', function (): void {
    expect(Schema::hasColumns('commitments', ['communication_id', 'customer_id', 'contact_id', 'direction', 'text', 'promised_at', 'due_at', 'status', 'follow_up_task_id', 'brief_item_id', 'extracted_by']))->toBeTrue();

    // SQLite does not store foreign-key constraint names, so the link is
    // asserted by its columns; the migration names it commitments_follow_up_task_fk.
    $followUp = collect(Schema::getForeignKeys('commitments'))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['follow_up_task_id']);

    expect($followUp)->not->toBeNull()
        ->and($followUp['foreign_table'])->toBe('tasks');
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('makes a replayed sync of the same external item a no-op', function (): void {
    $organizationId = CrmFixturePack::organizationId();
    CrmFixturePack::import();

    $row = [
        'id' => (string) Str::ulid(),
        'organization_id' => $organizationId,
        'source_type' => 'github_pull_request',
        'external_account_id' => '01M1GF81A07RD39TC6A1BRKMKG',
        'external_id' => 'STAFE-GROUP-AB/laravel-executive-ai#212',
        'fetched_at' => '2026-10-08 06:40:00',
        'occurred_at' => '2026-10-08 06:40:00',
        'content_hash' => 'sha256:'.str_repeat('a', 64),
        'payload' => '{}',
        'sensitivity_class' => 'internal',
        'provenance' => '{"origin":"sync","actor":"job:SyncSource","recorded_at":"2026-10-08T06:40:00+00:00","trace_id":null}',
    ];

    DB::table('source_records')->insert($row);

    expect(fn () => DB::table('source_records')->insert([...$row, 'id' => (string) Str::ulid()]))
        ->toThrow(QueryException::class);
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');

it('maintains one full-text index per searchable entity in the shape the profile prescribes', function (): void {
    foreach (SearchableEntity::cases() as $entity) {
        if (BackendProfile::isSqlite()) {
            expect(Schema::hasTable($entity->ftsTable()))->toBeTrue("FTS5 table {$entity->ftsTable()}");

            $triggers = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = ?", [$entity->table()]))->pluck('name');

            expect($triggers)->toContain("{$entity->ftsTable()}_after_insert")
                ->toContain("{$entity->ftsTable()}_after_update")
                ->toContain("{$entity->ftsTable()}_after_delete");

            continue;
        }

        expect(collect(Schema::getIndexes($entity->table()))->pluck('name'))
            ->toContain(MysqlFullTextGrammar::indexName($entity));
    }
})->group('backend-contract')->skip(fn (): bool => BackendProfile::serverWithoutMysql(), 'server profile selected but no MySQL connection configured');
