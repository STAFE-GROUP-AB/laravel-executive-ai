<?php

declare(strict_types=1);

use App\Models\PasskeyCredential;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * #117 acceptance: "Existing SQLite databases migrate forward without losing a
 * row." Asserting that on the suite's own database would prove nothing — it has
 * already run every migration, so the interesting state no longer exists there.
 *
 * So this file builds the interesting state: a throwaway SQLite file migrated to
 * the *old* shape only (users + the original `passkey_credentials`), given rows,
 * and then handed the one new migration. `migrate --database` makes Laravel swap
 * the default connection around the migration's `up()`
 * (`Migrator::runMethod()`), so the facades inside it act on that file and never
 * on the suite's database.
 *
 * No `DatabaseTransactions` here, and none is wanted: the work happens on a
 * connection of this file's own making and the file is deleted afterwards.
 * Nothing is dropped, wiped or refreshed — the throwaway file is created empty
 * and removed, and the suite's own database is never opened.
 */

const ROLL_FORWARD_CONNECTION = 'passkey_roll_forward';

const ROLL_FORWARD_USERS = 'database/migrations/0001_01_01_000000_create_users_table.php';

const ROLL_FORWARD_CREATE = 'database/migrations/2026_10_11_000001_create_passkey_credentials_table.php';

const ROLL_FORWARD_FIX = 'database/migrations/2026_10_13_000001_index_passkey_credential_id_by_hash.php';

/**
 * `passkey_credentials` exactly as PR #107 shipped it, before #117 amended the
 * create migration: `credential_id` at 1024 characters carrying the unique index
 * itself, and no hash column.
 *
 * It is written out here rather than produced by running the real migration file,
 * because that file is now the *fixed* shape — pointing this test at it would
 * quietly turn the roll-forward proof into a no-op on the day the fix landed. This
 * is the state an already-installed database is actually in.
 */
function rollForwardOldSchema(Connection $connection): void
{
    $connection->getSchemaBuilder()->create('passkey_credentials', function (Blueprint $table): void {
        $table->char('id', 26);
        $table->primary('id', 'passkey_credentials_pk');
        $table->char('user_id', 26);
        $table->string('name', 100);
        $table->string('credential_id', 1024);
        $table->text('public_key');
        $table->string('user_handle', 64);
        $table->unsignedBigInteger('sign_count')->default(0);
        $table->string('aaguid', 36);
        $table->json('transports');
        $table->string('attestation_type', 20);
        $table->json('trust_path');
        $table->boolean('backup_eligible')->nullable();
        $table->boolean('backed_up')->nullable();
        $table->boolean('uv_initialized')->nullable();
        $table->string('rp_id', 253);
        $table->string('origin', 300);
        $table->dateTime('last_used_at')->nullable();
        $table->dateTime('created_at')->nullable();
        $table->dateTime('updated_at')->nullable();

        $table->foreign('user_id', 'passkey_credentials_user_fk')->references('id')->on('users');
        $table->unique('credential_id', 'passkey_credentials_credential_id_unique');
        $table->index(['user_id', 'rp_id'], 'passkey_credentials_user_rp_index');
    });
}

/** @return array<string, mixed> One passkey row in the shape the old schema accepted. */
function rollForwardRow(string $userId, string $credentialId, string $name): array
{
    return [
        'id' => (string) Str::ulid(),
        'user_id' => $userId,
        'name' => $name,
        'credential_id' => $credentialId,
        'public_key' => base64_encode('cose-public-key-bytes'),
        'user_handle' => (string) Str::ulid(),
        'sign_count' => 3,
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'transports' => '["internal"]',
        'attestation_type' => 'none',
        'trust_path' => '{"type":"empty"}',
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'last_used_at' => '2026-10-12 06:30:00',
        'created_at' => '2026-10-12 06:00:00',
        'updated_at' => '2026-10-12 06:00:00',
    ];
}

/** @var array{path: ?string} Shared by reference between the hooks below. */
$state = ['path' => null];

beforeEach(function () use (&$state): void {
    $path = tempnam(sys_get_temp_dir(), 'lxai-passkey-rollforward-');

    if ($path === false) {
        throw new RuntimeException('Could not create a throwaway SQLite file.');
    }

    $state['path'] = $path;

    config(['database.connections.'.ROLL_FORWARD_CONNECTION => [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
});

afterEach(function () use (&$state): void {
    DB::purge(ROLL_FORWARD_CONNECTION);

    if (is_string($state['path']) && file_exists($state['path'])) {
        unlink($state['path']);
    }

    $state['path'] = null;
});

it('migrates a database that already ran the old shape forward with every row intact', function (): void {
    $connection = DB::connection(ROLL_FORWARD_CONNECTION);

    // 1. The world as it was before the fix.
    expect(Artisan::call('migrate', [
        '--database' => ROLL_FORWARD_CONNECTION,
        '--path' => [ROLL_FORWARD_USERS],
        '--realpath' => false,
        '--force' => true,
    ]))->toBe(0, 'migrate the users table');

    rollForwardOldSchema($connection);

    expect($connection->getSchemaBuilder()->hasColumn('passkey_credentials', 'credential_id_hash'))
        ->toBeFalse('the old shape has no hash column');

    expect(collect($connection->getSchemaBuilder()->getIndexes('passkey_credentials'))->pluck('name'))
        ->toContain('passkey_credentials_credential_id_unique');

    // 2. An owner's registered passkeys, written under the old shape.
    $userId = (string) Str::ulid();

    $connection->table('users')->insert([
        'id' => $userId,
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'email_verified_at' => '2026-10-12 06:00:00',
        'created_at' => '2026-10-12 06:00:00',
        'updated_at' => '2026-10-12 06:00:00',
    ]);

    $phone = rollForwardRow($userId, 'cGhvbmUtY3JlZGVudGlhbC1pZA', 'Phone');
    $key = rollForwardRow($userId, 'c2VjdXJpdHkta2V5LWNyZWRlbnRpYWw', 'Security key');

    $connection->table('passkey_credentials')->insert([$phone, $key]);

    // 3. The fix, and only the fix.
    expect(Artisan::call('migrate', [
        '--database' => ROLL_FORWARD_CONNECTION,
        '--path' => [ROLL_FORWARD_FIX],
        '--realpath' => false,
        '--force' => true,
    ]))->toBe(0, 'migrate the fix forward');

    // 4. Both rows are still there, unchanged, and each carries its own hash.
    $rows = $connection->table('passkey_credentials')->orderBy('name')->get();

    expect($rows)->toHaveCount(2, 'no row was lost');

    foreach ($rows as $row) {
        expect($row->credential_id_hash)->toBe(
            PasskeyCredential::hashFor((string) $row->credential_id),
            "backfilled hash for {$row->name}",
        );
    }

    $reloaded = $connection->table('passkey_credentials')->where('id', $phone['id'])->first();

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->credential_id)->toBe($phone['credential_id'])
        ->and($reloaded->public_key)->toBe($phone['public_key'])
        ->and($reloaded->user_handle)->toBe($phone['user_handle'])
        ->and((int) $reloaded->sign_count)->toBe(3, 'the sign counter survived')
        ->and($reloaded->last_used_at)->toBe($phone['last_used_at'])
        ->and($reloaded->created_at)->toBe($phone['created_at'])
        ->and($reloaded->rp_id)->toBe('localhost');

    // 5. The index moved, and the constraint moved with it.
    $names = collect($connection->getSchemaBuilder()->getIndexes('passkey_credentials'))->pluck('name');

    expect($names)->toContain('passkey_credentials_credential_id_hash_unique')
        ->and($names)->not->toContain('passkey_credentials_credential_id_unique');

    // The unrelated index was not collateral damage of the swap.
    expect($names)->toContain('passkey_credentials_user_rp_index');

    expect(fn () => $connection->table('passkey_credentials')->insert([
        ...rollForwardRow($userId, (string) $phone['credential_id'], 'A replay'),
        'credential_id_hash' => PasskeyCredential::hashFor((string) $phone['credential_id']),
    ]))->toThrow(QueryException::class);

    // 6. The user's foreign key still holds after the schema change.
    expect(fn () => $connection->table('passkey_credentials')->insert(
        rollForwardRow((string) Str::ulid(), 'b3JwaGFuLWNyZWRlbnRpYWw', 'Orphan')
    ))->toThrow(QueryException::class, '', 'the user foreign key survived');
});

it('is a no-op on a fresh database, where the create migration already has the fixed shape', function (): void {
    $connection = DB::connection(ROLL_FORWARD_CONNECTION);

    // The path a new install takes: the real create migration, now amended, then
    // this one. Both have to succeed, and the second must change nothing — which is
    // what the guards inside it are for.
    expect(Artisan::call('migrate', [
        '--database' => ROLL_FORWARD_CONNECTION,
        '--path' => [ROLL_FORWARD_USERS, ROLL_FORWARD_CREATE],
        '--realpath' => false,
        '--force' => true,
    ]))->toBe(0, 'migrate a fresh database to the amended shape');

    $before = $connection->table('sqlite_master')->orderBy('name')->pluck('sql', 'name')->all();

    expect(Artisan::call('migrate', [
        '--database' => ROLL_FORWARD_CONNECTION,
        '--path' => [ROLL_FORWARD_FIX],
        '--realpath' => false,
        '--force' => true,
    ]))->toBe(0, 'the fix runs clean against the fixed shape');

    expect($connection->table('sqlite_master')->orderBy('name')->pluck('sql', 'name')->all())
        ->toBe($before, 'the fix migration changed nothing on a fresh database');

    $names = collect($connection->getSchemaBuilder()->getIndexes('passkey_credentials'))->pluck('name');

    expect($names)->toContain('passkey_credentials_credential_id_hash_unique')
        ->and($names)->not->toContain('passkey_credentials_credential_id_unique');
});

it('leaves the credential id column able to hold the spec maximum on sqlite without a table rebuild', function (): void {
    $connection = DB::connection(ROLL_FORWARD_CONNECTION);

    Artisan::call('migrate', [
        '--database' => ROLL_FORWARD_CONNECTION,
        '--path' => [ROLL_FORWARD_USERS],
        '--realpath' => false,
        '--force' => true,
    ]);

    rollForwardOldSchema($connection);

    Artisan::call('migrate', [
        '--database' => ROLL_FORWARD_CONNECTION,
        '--path' => [ROLL_FORWARD_FIX],
        '--realpath' => false,
        '--force' => true,
    ]);

    $userId = (string) Str::ulid();

    $connection->table('users')->insert([
        'id' => $userId,
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'created_at' => '2026-10-12 06:00:00',
        'updated_at' => '2026-10-12 06:00:00',
    ]);

    // SQLite does not enforce a VARCHAR length, which is exactly why the fix does
    // not rebuild the table to widen the column there: the spec maximum already
    // round-trips through the original `varchar(1024)` declaration.
    $longest = rtrim(strtr(base64_encode(str_repeat("\x41", 1023)), '+/', '-_'), '=');

    expect($longest)->toHaveLength(PasskeyCredential::MAX_CREDENTIAL_ID_LENGTH);

    $row = rollForwardRow($userId, $longest, 'Security key');

    $connection->table('passkey_credentials')->insert([
        ...$row,
        'credential_id_hash' => PasskeyCredential::hashFor($longest),
    ]);

    $stored = $connection->table('passkey_credentials')->where('id', $row['id'])->value('credential_id');

    expect($stored)->toBe($longest)->toHaveLength(1364);

    // Read the table's own DDL from sqlite_master — the stored declaration, not
    // Laravel's normalised view of it. Laravel's SQLite grammar emits a bare
    // `varchar` with no length, so the `1024` in the original migration never
    // reached this profile at all: there is nothing here to widen, and the 1364
    // characters inserted above are the measurement that says so.
    $ddl = (string) $connection->table('sqlite_master')
        ->where('type', 'table')
        ->where('name', 'passkey_credentials')
        ->value('sql');

    expect($ddl)->toContain('"credential_id" varchar not null')
        ->and($ddl)->not->toContain('varchar(1024)')
        ->and($ddl)->not->toContain('varchar(1364)')
        ->and(mb_strlen($stored))->toBeGreaterThan(1024);
});
