<?php

declare(strict_types=1);

use App\Models\CRM\ContactEmail;
use App\Security\Secrets\EncryptedAttributeInventory;
use App\Security\Secrets\SensitiveAttributeRewriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SensitiveAttributeFixtures;

/*
 * S2-04 (#87) acceptance: "existing rows migrate forward without losing one." The
 * suite's own database proves nothing about that — it has already run every migration,
 * so the interesting state no longer exists there.
 *
 * So this file builds it: a throwaway SQLite file migrated with **every migration
 * except the encryption one**, given one plaintext row in every inventoried table, and
 * then handed that one migration. `migrate --database` makes Laravel swap the default
 * connection around the migration's `up()` (`Migrator::runMethod()`), so the facades
 * and the models inside it act on that file and never on the suite's database.
 *
 * No `DatabaseTransactions` here, and none is wanted: the work happens on a connection
 * of this file's own making and the file is deleted afterwards. Nothing is dropped,
 * wiped or refreshed — the throwaway file is created empty and removed, and the suite's
 * own database is never opened.
 */

const ENCRYPTION_CONNECTION = 'sensitive_attribute_roll_forward';

const ENCRYPTION_MIGRATION = 'database/migrations/2026_10_13_000013_encrypt_sensitive_attributes_at_rest.php';

/**
 * Every migration the repository ships **except** the encryption one, in order — the
 * world as it was the moment before this item landed.
 *
 * It is derived from the directory rather than listed, so a migration added by another
 * item is included automatically instead of silently leaving this test on an old shape.
 *
 * @return list<string>
 */
function migrationsBeforeEncryption(): array
{
    $files = glob('database/migrations/*.php');
    sort($files);

    return array_values(array_filter($files, fn (string $path): bool => $path !== ENCRYPTION_MIGRATION));
}

/** @param list<string> $paths */
function migrateThrowaway(array $paths): int
{
    return Artisan::call('migrate', [
        '--database' => ENCRYPTION_CONNECTION,
        '--path' => $paths,
        '--realpath' => false,
        '--force' => true,
    ]);
}

/** @var array{path: ?string} Shared by reference between the hooks below. */
$state = ['path' => null];

beforeEach(function () use (&$state): void {
    $path = tempnam(sys_get_temp_dir(), 'lxai-encrypt-rollforward-');

    if ($path === false) {
        throw new RuntimeException('Could not create a throwaway SQLite file.');
    }

    $state['path'] = $path;

    config(['database.connections.'.ENCRYPTION_CONNECTION => [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
});

afterEach(function () use (&$state): void {
    DB::purge(ENCRYPTION_CONNECTION);

    if (is_string($state['path']) && file_exists($state['path'])) {
        unlink($state['path']);
    }

    $state['path'] = null;
});

it('encrypts every plaintext row an existing install already holds, and loses none', function (): void {
    $connection = DB::connection(ENCRYPTION_CONNECTION);

    expect(migrateThrowaway(migrationsBeforeEncryption()))->toBe(0, 'migrate the pre-S2-04 shape');

    // The pre-S2-04 world: the hash column does not exist, and the unique index is on
    // the address itself.
    expect($connection->getSchemaBuilder()->hasColumn('contact_emails', 'address_hash'))->toBeFalse()
        ->and(collect($connection->getSchemaBuilder()->getIndexes('contact_emails'))
            ->firstWhere('name', 'contact_emails_organization_address_unique')['columns'])
        ->toBe(['organization_id', 'address']);

    // One plaintext row per inventoried table, written the way the app wrote them
    // before the casts existed.
    $ids = SensitiveAttributeFixtures::insert($connection);
    $plaintext = SensitiveAttributeFixtures::plaintext();
    $countsBefore = [];

    foreach (SensitiveAttributeFixtures::tablesInReverseDependencyOrder() as $table) {
        $countsBefore[$table] = $connection->table($table)->count();
    }

    // Readable in the database file, which is the thing this item removes.
    expect((string) $connection->table('contact_emails')->where('id', $ids['contact_emails'])->value('address'))
        ->toBe(SensitiveAttributeFixtures::ADDRESS);

    // The migration, and only the migration.
    expect(migrateThrowaway([ENCRYPTION_MIGRATION]))->toBe(0, 'migrate the encryption forward');

    // 1. Not one row was lost.
    foreach ($countsBefore as $table => $count) {
        expect($connection->table($table)->count())->toBe($count, "{$table} kept every row");
    }

    // 2. Every inventoried column is ciphertext now, and decrypts to exactly the bytes
    //    that were there before.
    foreach (SensitiveAttributeFixtures::inventoryRows() as ['table' => $table, 'column' => $column]) {
        $stored = (string) $connection->table($table)->where('id', $ids[$table])->value($column);
        $expected = $plaintext["{$table}.{$column}"];

        expect(SensitiveAttributeRewriter::isCiphertext($stored))->toBeTrue("{$table}.{$column} was encrypted")
            ->and(str_contains($stored, $expected))->toBeFalse("{$table}.{$column} no longer holds its plaintext")
            ->and(SensitiveAttributeRewriter::plaintextOf($stored))->toBe($expected, "{$table}.{$column} kept its exact bytes");
    }

    // 3. The columns that search depends on were not touched — this is the half that
    //    keeps the S2-01 search contract true, asserted on the data and not only on
    //    the inventory.
    $contact = $connection->table('contacts')->where('id', $ids['contacts'])->first();
    $communication = $connection->table('communications')->where('id', $ids['communications'])->first();

    expect($contact->display_name)->toBe('Ada Lovelace')
        ->and($contact->given_name)->toBe('Ada')
        ->and($contact->family_name)->toBe('Lovelace')
        ->and($contact->title)->toBe('CTO')
        ->and($contact->notes)->toBe('Prefers email.')
        ->and($communication->subject)->toBe('Offert Mölndal')
        ->and($communication->excerpt)->toBe('Consegna prevista entro venerdì.')
        // And so was `to_addresses`, which is matched by exact lookup rather than read.
        ->and($communication->to_addresses)->toBe('["owner@example.test"]');

    // 4. The unique index moved onto the hash and kept its name, and the hash is the
    //    hash of this row's own address.
    $index = collect($connection->getSchemaBuilder()->getIndexes('contact_emails'))
        ->firstWhere('name', 'contact_emails_organization_address_unique');

    expect($index)->not->toBeNull('the S2-01 index name survived')
        ->and($index['unique'])->toBeTrue()
        ->and($index['columns'])->toBe(['organization_id', 'address_hash'])
        ->and((string) $connection->table('contact_emails')->where('id', $ids['contact_emails'])->value('address_hash'))
        ->toBe(ContactEmail::hashFor(SensitiveAttributeFixtures::ADDRESS));

    // 5. And it still constrains: one address per workspace is unrecordable twice,
    //    which is what the index was there for in the first place.
    $duplicate = collect(SensitiveAttributeFixtures::rows())->firstWhere('table', 'contact_emails')['row'];
    $existing = $connection->table('contact_emails')->where('id', $ids['contact_emails'])->first();

    expect(fn () => $connection->table('contact_emails')->insert([
        ...$duplicate,
        'id' => (string) Str::ulid(),
        'organization_id' => $existing->organization_id,
        'contact_id' => $existing->contact_id,
        'address' => $existing->address,
        'address_hash' => $existing->address_hash,
        'position' => 1,
    ]))->toThrow(QueryException::class);
});

it('backfills every row when there are more of them than one chunk holds', function (): void {
    $connection = DB::connection(ENCRYPTION_CONNECTION);

    migrateThrowaway(migrationsBeforeEncryption());

    $ids = SensitiveAttributeFixtures::insert($connection);
    $seed = $connection->table('contact_emails')->where('id', $ids['contact_emails'])->first();

    // One row past the chunk boundary — the smallest number that exercises a second
    // page at all, and the number at which a `chunk()`-based backfill fails *silently*:
    // `chunk()` pages by raw offset, so once the loop has written to the column it
    // filters on, page two reads past the rows that moved into its range. One leftover
    // row is not a duplicate, so the unique index is created happily and an address
    // exists that `forAddress()` can never find. The count comes from the constant the
    // rewriter actually uses, so the two cannot drift apart.
    $total = SensitiveAttributeRewriter::CHUNK + 1;
    $rows = [];

    for ($i = 1; $i < $total; $i++) {
        $rows[] = [
            ...(array) $seed,
            'id' => (string) Str::ulid(),
            'address' => "ada.lovelace+{$i}@example.test",
            'position' => $i,
        ];
    }

    foreach (array_chunk($rows, 100) as $batch) {
        $connection->table('contact_emails')->insert($batch);
    }

    expect($connection->table('contact_emails')->count())->toBe($total);

    expect(migrateThrowaway([ENCRYPTION_MIGRATION]))->toBe(0, 'the backfill migration completed');

    // Not one row may be left on the ADD COLUMN default …
    expect($connection->table('contact_emails')->where('address_hash', '')->count())
        ->toBe(0, 'every row was hashed');

    // … nor left in plaintext …
    $plaintextRows = 0;
    $mismatched = 0;

    foreach ($connection->table('contact_emails')->orderBy('id')->get() as $row) {
        $stored = (string) $row->address;

        if (! SensitiveAttributeRewriter::isCiphertext($stored)) {
            $plaintextRows++;

            continue;
        }

        // … and every hash must be the hash of its *own* address: a cursor bug that
        // re-hashed the wrong row would pass a mere "no empty strings" check.
        if (! hash_equals(ContactEmail::hashFor(SensitiveAttributeRewriter::plaintextOf($stored)), (string) $row->address_hash)) {
            $mismatched++;
        }
    }

    expect($plaintextRows)->toBe(0, 'every row was encrypted')
        ->and($mismatched)->toBe(0, 'every hash belongs to its own address')
        ->and($connection->table('contact_emails')->distinct()->count('address_hash'))
        ->toBe($total, 'the hashes are as distinct as the addresses were');
});

it('changes no data on a fresh install, where there is nothing to bring forward', function (): void {
    $connection = DB::connection(ENCRYPTION_CONNECTION);

    // The path a new install takes: every create migration, then this one, over empty
    // tables. The schema steps must run — a fresh database needs `address_hash` and the
    // moved index like any other — and the row rewrites must be empty passes.
    expect(migrateThrowaway(migrationsBeforeEncryption()))->toBe(0);

    foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
        expect($connection->table($table)->count())->toBe(0, "{$table} is empty on a fresh install");
    }

    expect(migrateThrowaway([ENCRYPTION_MIGRATION]))->toBe(0, 'the migration runs clean against an empty database');

    foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
        expect($connection->table($table)->count())->toBe(0, "{$table} is still empty");
    }

    // The schema it gives a fresh database is the same schema it leaves an upgraded
    // one with: the hash column, and the named unique index over it.
    $index = collect($connection->getSchemaBuilder()->getIndexes('contact_emails'))
        ->firstWhere('name', 'contact_emails_organization_address_unique');

    expect($connection->getSchemaBuilder()->hasColumn('contact_emails', 'address_hash'))->toBeTrue()
        ->and($index['columns'])->toBe(['organization_id', 'address_hash'])
        ->and($index['unique'])->toBeTrue();

    // And it is idempotent: a second run of the same file does nothing at all. The
    // migrator would never call it twice, but every step is guarded so that a replay
    // after an interrupted run cannot double-encrypt a value or hash a ciphertext.
    $ddl = $connection->table('sqlite_master')->orderBy('name')->pluck('sql', 'name')->all();

    $connection->table('migrations')->where('migration', '2026_10_13_000013_encrypt_sensitive_attributes_at_rest')->delete();

    expect(migrateThrowaway([ENCRYPTION_MIGRATION]))->toBe(0, 'a replay of the migration is clean')
        ->and($connection->table('sqlite_master')->orderBy('name')->pluck('sql', 'name')->all())
        ->toBe($ddl, 'a replay changed nothing');
});

it('is idempotent over rows it has already encrypted', function (): void {
    $connection = DB::connection(ENCRYPTION_CONNECTION);

    migrateThrowaway(migrationsBeforeEncryption());

    $ids = SensitiveAttributeFixtures::insert($connection);
    $plaintext = SensitiveAttributeFixtures::plaintext();

    migrateThrowaway([ENCRYPTION_MIGRATION]);

    $after = [];

    foreach (SensitiveAttributeFixtures::inventoryRows() as ['table' => $table, 'column' => $column]) {
        $after["{$table}.{$column}"] = (string) $connection->table($table)->where('id', $ids[$table])->value($column);
    }

    // Replay the file over rows it has already encrypted. The value test in
    // `SensitiveAttributeRewriter::isCiphertext()` is what stops a second pass from
    // encrypting the ciphertext — which would produce a value nothing can ever read,
    // the one irreversible mistake available here.
    $connection->table('migrations')->where('migration', '2026_10_13_000013_encrypt_sensitive_attributes_at_rest')->delete();

    expect(migrateThrowaway([ENCRYPTION_MIGRATION]))->toBe(0);

    foreach (SensitiveAttributeFixtures::inventoryRows() as ['table' => $table, 'column' => $column]) {
        $qualified = "{$table}.{$column}";
        $stored = (string) $connection->table($table)->where('id', $ids[$table])->value($column);

        expect($stored)->toBe($after[$qualified], "{$qualified} was not re-encrypted")
            ->and(SensitiveAttributeRewriter::plaintextOf($stored))->toBe($plaintext[$qualified], "{$qualified} is still readable");
    }
});
