<?php

declare(strict_types=1);

use App\CRM\Search\SearchableEntity;
use App\Security\Secrets\EncryptedAttributeInventory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\Support\BackendProfile;

/*
 * S2-04 (#87) arch gate. The §3.1 encrypted attributes are declared once, in
 * App\Security\Secrets\EncryptedAttributeInventory, and this file holds every
 * property that makes that declaration safe:
 *
 *  - the casts and the inventory are the same list, in both directions, so
 *    ADR-003 D8's re-encryption job can never fall behind a cast — and so an
 *    `encrypted:` cast added by any item other than this one fails the suite;
 *  - no inventoried column is a token, password, key or verifier (ADR-003 D1,
 *    Forbidden 1);
 *  - no inventoried column is in a full-text index, because ciphertext cannot be
 *    searched (ADR-002 D4, S2-01's frozen search contract);
 *  - no inventoried column carries an index of any kind, because Laravel's
 *    encrypter is randomised and an index over ciphertext constrains nothing;
 *  - every inventoried column exists, and is wide enough to hold a payload that is
 *    about 2.4× its plaintext.
 *
 * These are properties of the schema and the model layer, so the file needs no
 * database writes and no DatabaseTransactions.
 */

/**
 * Every concrete Eloquent model in the application.
 *
 * The whole of `app/` is scanned, not just `app/Models/`. The repository's convention
 * puts models under `app/Models/`, but an arch gate that trusted the convention it is
 * policing would miss the one case it exists for: a cast on a model somebody put
 * somewhere else. `TenantRowFactory::modelClasses()` (S2-06) scans the same way, for
 * the same reason.
 *
 * @return list<class-string<Model>>
 */
function modelClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relative = Str::of($file->getRelativePathname())->replace(['/', '.php'], ['\\', ''])->toString();
        $class = 'App\\'.$relative;

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/** @return list<string> `<table>.<column>` for every encrypted cast actually resolved by a model */
function encryptedCastsInUse(): array
{
    $found = [];

    foreach (modelClasses() as $class) {
        /** @var Model $model */
        $model = new $class;

        foreach ($model->getCasts() as $column => $cast) {
            if (! str_starts_with($cast, 'encrypted')) {
                continue;
            }

            $found[] = "{$model->getTable()}.{$column}";
        }
    }

    sort($found);

    return $found;
}

it('declares exactly the encrypted casts the models resolve, in both directions', function (): void {
    // One list, compared whole. A cast missing from the inventory means the
    // rotation job would not re-encrypt it; an inventory entry with no cast means
    // the column is documented as protected while it is stored in the clear.
    expect(encryptedCastsInUse())->toBe(EncryptedAttributeInventory::qualifiedColumns());
})->group('security');

it('writes an encrypted cast in exactly one file, and that file is the inventory', function (): void {
    // The directional half the comparison above cannot see. A model that declared
    // `'notes' => 'encrypted'` itself rather than through the trait would also show up
    // in `encryptedCastsInUse()` and fail the test above — but only until somebody
    // added it to the inventory as well, and by then the habit would be established.
    // This keeps the cast literal in one place, so the review question is always "why
    // is this in the inventory" and never "where did this cast come from".
    //
    // The whole of `app/` is searched, because a cast is only required to sit near a
    // model by convention, and the convention is what this is checking.
    $sanctioned = 'Security/Secrets/EncryptedAttributeInventory.php';
    $offenders = [];

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());

        if ($relative === $sanctioned) {
            continue;
        }

        if (preg_match("/=>\s*'encrypted/", (string) file_get_contents($file->getRealPath())) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe(
        [],
        'S2-04 (#87) is the only item that may add an `encrypted:` cast, and it adds it to '
        ."app/{$sanctioned} — not to a model and not anywhere else: ".implode(', ', $offenders),
    );

    // And the sanctioned file really is where they are written, so this cannot pass by
    // the casts having quietly moved somewhere the search does not look.
    expect(preg_match("/=>\s*'encrypted/", (string) file_get_contents(app_path($sanctioned))))
        ->toBe(1, 'the inventory is where the `encrypted:` casts are written');
})->group('security');

it('encrypts no column that looks like a credential', function (): void {
    // ADR-003 D1, Forbidden 1: credentials live in the Keychain and no column holds
    // one, encrypted or not. Same pattern the S2-03 governance contract test uses
    // over whole tables, applied here to the inventory.
    $forbidden = '/(^|_)(token|password|passphrase|api_key|client_secret|refresh|verifier|credential|private_key)($|_)/';

    foreach (EncryptedAttributeInventory::qualifiedColumns() as $qualified) {
        [, $column] = explode('.', $qualified, 2);

        expect($column)->not->toMatch(
            $forbidden,
            "ADR-003 D1: {$qualified} looks like a credential — a credential belongs in the Keychain, not in an encrypted column",
        );
    }
})->group('security');

it('encrypts no column that a full-text index covers', function (): void {
    // The reason the CRM search contract (S2-01) still passes unchanged. If this
    // fails, a later item has encrypted a searchable column and the frozen hit set
    // on both profiles is about to go quiet rather than red.
    $indexed = [];

    foreach (SearchableEntity::cases() as $entity) {
        foreach ($entity->columns() as $column) {
            $indexed[] = "{$entity->table()}.{$column}";
        }
    }

    foreach (EncryptedAttributeInventory::qualifiedColumns() as $qualified) {
        expect($indexed)->not->toContain(
            $qualified,
            "{$qualified} is in a full-text index (ADR-002 D4); ciphertext tokenises to noise, so it cannot be encrypted",
        );
    }
})->group('security');

it('encrypts no column that an index reads', function (): void {
    // Laravel's encrypter draws a fresh IV per call, so the same plaintext never
    // produces the same ciphertext twice: a unique index over an encrypted column
    // constrains nothing and an equality lookup never hits. The one column that was
    // indexed — contact_emails.address — had its constraint moved onto the keyed
    // hash beside it, which is what the next test checks.
    foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
        foreach (Schema::getIndexes($table) as $index) {
            foreach ((array) $index['columns'] as $indexed) {
                expect($columns)->not->toContain(
                    (string) $indexed,
                    "index {$index['name']} reads {$table}.{$indexed}, which is encrypted — move the constraint onto a keyed hash column instead",
                );
            }
        }
    }
})->group('security');

it('keeps the contact email uniqueness constraint, on the hash that can carry it', function (): void {
    $index = collect(Schema::getIndexes('contact_emails'))
        ->firstWhere('name', 'contact_emails_organization_address_unique');

    // The name is the one S2-01's frozen contract test asserts; only the columns
    // under it moved (#117's pattern, applied to an encrypted column).
    expect($index)->not->toBeNull('the S2-01 index name is unchanged')
        ->and($index['unique'])->toBeTrue()
        ->and(array_map(mb_strtolower(...), (array) $index['columns']))->toBe(['organization_id', 'address_hash']);
})->group('security');

it('has a column for every inventoried attribute, wide enough for its ciphertext', function (): void {
    foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::hasColumn($table, $column))->toBeTrue("{$table}.{$column}");

            if (BackendProfile::isSqlite()) {
                // SQLite neither enforces nor emits a VARCHAR length and compiles
                // `json()` to `text`, so every one of these columns is already
                // unbounded here and the S2-04 migration widens nothing (#117
                // measured the same thing for `credential_id`).
                continue;
            }

            $type = collect(Schema::getColumns($table))->firstWhere('name', $column);

            // A Laravel payload is base64 of a JSON envelope around the padded
            // ciphertext — about 2.4× the input — and MySQL runs
            // STRICT_TRANS_TABLES, so an overflow is an error and not a silent
            // truncation. `mediumtext` is the portable headroom.
            expect(mb_strtolower((string) $type['type_name']))->toBe('mediumtext', "{$table}.{$column} on the server profile");
        }
    }
})->group('security');
