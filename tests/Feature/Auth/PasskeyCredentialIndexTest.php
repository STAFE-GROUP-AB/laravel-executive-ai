<?php

declare(strict_types=1);

use App\Models\PasskeyCredential;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\BackendProfile;

uses(DatabaseTransactions::class);

/*
 * #117: `passkey_credentials` could not be migrated on the ADR-002 D1 `server`
 * profile, because a unique index on a 1024-character `utf8mb4` column is 4096
 * bytes against InnoDB's 3072-byte limit. The identity of a credential is now
 * indexed through `credential_id_hash`.
 *
 * Three things have to stay true, and each is easy to break by accident later:
 * the duplicate is still refused (the index moved, the guarantee did not), the
 * spec's longest credential id is storable, and a database that already ran the
 * old shape migrates forward with its rows.
 */

/** Longest credential id WebAuthn permits: unpadded base64url of 1023 bytes. */
$longestCredentialId = rtrim(strtr(base64_encode(str_repeat("\x41", 1023)), '+/', '-_'), '=');

/** @return array<string, mixed> One passkey row, old shape (no hash column). */
$row = function (string $userId, string $credentialId): array {
    return [
        'id' => (string) Str::ulid(),
        'user_id' => $userId,
        'name' => 'Phone',
        'credential_id' => $credentialId,
        'public_key' => base64_encode('cose-public-key-bytes'),
        'user_handle' => (string) Str::ulid(),
        'sign_count' => 0,
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'transports' => '["internal"]',
        'attestation_type' => 'none',
        'trust_path' => '{"type":"empty"}',
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
        'created_at' => '2026-10-12 06:00:00',
        'updated_at' => '2026-10-12 06:00:00',
    ];
};

it('indexes the credential id by hash and leaves the id itself unindexed', function (): void {
    $names = collect(Schema::getIndexes('passkey_credentials'))->pluck('name')->all();

    expect($names)->toContain('passkey_credentials_credential_id_hash_unique')
        ->and($names)->not->toContain('passkey_credentials_credential_id_unique')
        ->and(Schema::hasColumn('passkey_credentials', 'credential_id_hash'))->toBeTrue();

    // The column is fixed width on MySQL and the portable `varchar` on SQLite,
    // whose grammar has no CHAR type — the same declaration either way.
    $hash = collect(Schema::getColumns('passkey_credentials'))->firstWhere('name', 'credential_id_hash');

    expect($hash)->not->toBeNull()
        ->and(mb_strtolower((string) $hash['type']))->toBe(BackendProfile::isSqlite() ? 'varchar' : 'char(64)');
});

it('still refuses a duplicate credential id, through the hash', function () use ($row): void {
    $user = User::factory()->create();
    $credentialId = 'dGhlLXNhbWUtY3JlZGVudGlhbC1pZA';

    DB::table('passkey_credentials')->insert([
        ...$row((string) $user->id, $credentialId),
        'credential_id_hash' => PasskeyCredential::hashFor($credentialId),
    ]);

    expect(fn () => DB::table('passkey_credentials')->insert([
        ...$row((string) $user->id, $credentialId),
        'credential_id_hash' => PasskeyCredential::hashFor($credentialId),
    ]))->toThrow(QueryException::class);

    // A different credential for the same user is not a duplicate.
    DB::table('passkey_credentials')->insert([
        ...$row((string) $user->id, 'YS1kaWZmZXJlbnQtY3JlZGVudGlhbA'),
        'credential_id_hash' => PasskeyCredential::hashFor('YS1kaWZmZXJlbnQtY3JlZGVudGlhbA'),
    ]);

    expect(DB::table('passkey_credentials')->where('user_id', $user->id)->count())->toBe(2);
});

it('stores and returns the longest credential id WebAuthn permits', function () use ($longestCredentialId): void {
    expect($longestCredentialId)->toHaveLength(PasskeyCredential::MAX_CREDENTIAL_ID_LENGTH);

    $credential = PasskeyCredential::query()->create([
        ...[
            'user_id' => User::factory()->create()->id,
            'name' => 'Security key',
            'credential_id' => $longestCredentialId,
            'public_key' => base64_encode('cose-public-key-bytes'),
            'user_handle' => (string) Str::ulid(),
            'aaguid' => '00000000-0000-0000-0000-000000000000',
            'transports' => ['usb'],
            'attestation_type' => 'none',
            'trust_path' => ['type' => 'empty'],
            'rp_id' => 'localhost',
            'origin' => 'http://localhost',
        ],
    ]);

    $stored = PasskeyCredential::query()->findOrFail($credential->id);

    expect($stored->credential_id)->toBe($longestCredentialId)
        ->and($stored->credential_id)->toHaveLength(1364)
        ->and($stored->credential_id_hash)->toBe(hash('sha256', $longestCredentialId));
});

it('derives the hash on every save so no row can carry a mismatched index key', function (): void {
    $credential = PasskeyCredential::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Phone',
        'credential_id' => 'Zmlyc3QtY3JlZGVudGlhbA',
        'public_key' => base64_encode('cose-public-key-bytes'),
        'user_handle' => (string) Str::ulid(),
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'transports' => ['internal'],
        'attestation_type' => 'none',
        'trust_path' => ['type' => 'empty'],
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
    ]);

    // Set on insert without the caller supplying it...
    expect($credential->credential_id_hash)->toBe(hash('sha256', 'Zmlyc3QtY3JlZGVudGlhbA'));

    // ...and recomputed when the credential id changes, even if a caller also
    // tries to supply a hash that does not match.
    $credential->credential_id = 'c2Vjb25kLWNyZWRlbnRpYWw';
    $credential->credential_id_hash = hash('sha256', 'a-lie');
    $credential->save();

    expect($credential->fresh()->credential_id_hash)->toBe(hash('sha256', 'c2Vjb25kLWNyZWRlbnRpYWw'));

    // An unrelated update leaves the hash alone.
    $credential->sign_count = 7;
    $credential->save();

    expect($credential->fresh()->credential_id_hash)->toBe(hash('sha256', 'c2Vjb25kLWNyZWRlbnRpYWw'));
});

it('finds a credential by its id through the index that exists', function (): void {
    $credential = PasskeyCredential::query()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Phone',
        'credential_id' => 'bG9va3VwLW1l',
        'public_key' => base64_encode('cose-public-key-bytes'),
        'user_handle' => (string) Str::ulid(),
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'transports' => ['internal'],
        'attestation_type' => 'none',
        'trust_path' => ['type' => 'empty'],
        'rp_id' => 'localhost',
        'origin' => 'http://localhost',
    ]);

    $found = PasskeyCredential::query()->forCredentialId('bG9va3VwLW1l')->first();

    expect($found?->id)->toBe($credential->id)
        ->and(PasskeyCredential::query()->forCredentialId('bm90LXN0b3JlZA')->first())->toBeNull();

    // The query goes through the hash column, not the unindexed credential id.
    $sql = PasskeyCredential::query()->forCredentialId('bG9va3VwLW1l')->toSql();

    expect($sql)->toContain('credential_id_hash')
        ->and($sql)->not->toContain('"credential_id" =')
        ->and($sql)->not->toContain('`credential_id` =');
});
