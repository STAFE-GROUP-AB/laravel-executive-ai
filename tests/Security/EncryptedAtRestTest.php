<?php

declare(strict_types=1);

use App\Models\CRM\ContactEmail;
use App\Security\Secrets\EncryptedAttributeInventory;
use App\Security\Secrets\SensitiveAttributeRewriter;
use App\Security\Secrets\UnreadableEncryptedAttributeException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApplicationKeys;
use Tests\Support\SensitiveAttributeFixtures;

/*
 * S2-04 (#87), blueprint §3.1 "sensitive attributes encrypted at rest". Two
 * properties, and the second matters as much as the first:
 *
 *  1. The encryption is **real**: the value in the database column is not the
 *     plaintext, and the same row read back through the model is.
 *  2. A lost `core.app-key` **fails closed**: the read raises an exception that says
 *     what happened, rather than returning the ciphertext, an empty string, or
 *     anything else a caller might mistake for the value. A25 is the decision that
 *     keeps the key and the data together across an uninstall; this is what happens
 *     when they come apart anyway.
 *
 * DatabaseTransactions, never the refresh-database trait (CLAUDE.md, ADR-002 D10).
 */

uses(DatabaseTransactions::class);

it('covers every inventoried attribute with a fixture', function (): void {
    // The fixture is what the tests below write; if it stops covering the whole
    // inventory they keep passing while protecting less. The inventory test asserts
    // the inventory against the casts; this asserts the fixture against the inventory.
    expect(SensitiveAttributeFixtures::coversTheWholeInventory())->toBeTrue();
})->group('security');

it('stores ciphertext in the column and hands the plaintext back through the model', function (): void {
    $connection = DB::connection();
    $ids = SensitiveAttributeFixtures::insert($connection);

    // The rows went in through the query builder, so they are plaintext — the state a
    // local install was in before this item. One rewriter pass is what the S2-04
    // migration does to them.
    $rewritten = app(SensitiveAttributeRewriter::class)->encryptPlaintext();

    expect(array_sum($rewritten))->toBe(
        count(EncryptedAttributeInventory::qualifiedColumns()),
        'every inventoried column had exactly one plaintext row to rewrite',
    );

    $plaintext = SensitiveAttributeFixtures::plaintext();

    foreach (SensitiveAttributeFixtures::inventoryRows() as ['model' => $model, 'table' => $table, 'column' => $column]) {
        $id = $ids[$table];
        $expected = $plaintext["{$table}.{$column}"];
        $stored = (string) $connection->table($table)->where('id', $id)->value($column);

        // Not the plaintext, not containing it, and a Laravel payload.
        expect($stored)->not->toBe($expected, "{$table}.{$column} is stored as ciphertext")
            ->and(str_contains($stored, $expected))->toBeFalse("{$table}.{$column} does not contain its plaintext")
            ->and(SensitiveAttributeRewriter::isCiphertext($stored))->toBeTrue("{$table}.{$column} is a Laravel payload");

        // Nothing recognisable leaks either: a 20-character window of the plaintext
        // must not appear anywhere in the stored value.
        if (mb_strlen($expected) >= 20) {
            expect($stored)->not->toContain(mb_substr($expected, 0, 20), "{$table}.{$column} leaks a prefix of its plaintext");
        }

        // The plaintext inside the payload is byte-identical to what was inserted.
        // This is the strong form of the claim, and the reason the rewriter treats a
        // value as an opaque string rather than round-tripping it through a cast: a
        // `json` document re-encoded by PHP comes back with different unicode
        // escaping, which would be a silent change to someone's data.
        expect(SensitiveAttributeRewriter::plaintextOf($stored))
            ->toBe($expected, "{$table}.{$column} decrypts to exactly the bytes that were inserted");

        // And read back through the model it is the value again, in the shape the
        // cast promises: a string for `encrypted`, the decoded document for
        // `encrypted:array`.
        $read = $model::query()->findOrFail($id)->{$column};

        expect($read)->toBe(
            is_array(json_decode($expected, true)) && ! is_string($read) ? json_decode($expected, true) : $expected,
            "{$table}.{$column} reads back as it was written",
        );
    }
})->group('security');

it('keeps the blind index in step with the address on every write', function (): void {
    $connection = DB::connection();
    $ids = SensitiveAttributeFixtures::insert($connection);
    app(SensitiveAttributeRewriter::class)->encryptPlaintext();
    app(SensitiveAttributeRewriter::class)->rewriteContactEmailHashes(onlyMissing: false);

    $email = ContactEmail::query()->findOrFail($ids['contact_emails']);

    expect($email->address)->toBe(SensitiveAttributeFixtures::ADDRESS)
        ->and($email->address_hash)->toBe(ContactEmail::hashFor(SensitiveAttributeFixtures::ADDRESS))
        ->and(ContactEmail::query()->forAddress(SensitiveAttributeFixtures::ADDRESS)->firstOrFail()->id)->toBe($email->id)
        // Case and surrounding space are normalised away, so "the sender address is
        // the primary address of contact X" (§5.6) is a deterministic rule however the
        // provider spelled the header.
        ->and(ContactEmail::query()->forAddress('  '.mb_strtoupper(SensitiveAttributeFixtures::ADDRESS).' ')->firstOrFail()->id)->toBe($email->id)
        ->and(ContactEmail::query()->forAddress(SensitiveAttributeFixtures::SECOND_ADDRESS)->exists())->toBeFalse();

    // The hash is derived on save, not supplied: changing the address moves the index
    // key with it, so no row can hold a key that disagrees with its value.
    $email->address = SensitiveAttributeFixtures::SECOND_ADDRESS;
    $email->save();

    expect(ContactEmail::query()->forAddress(SensitiveAttributeFixtures::SECOND_ADDRESS)->firstOrFail()->id)->toBe($email->id)
        ->and(ContactEmail::query()->forAddress(SensitiveAttributeFixtures::ADDRESS)->exists())->toBeFalse()
        // And the hash is not a copy of the address: a keyed digest, so the stored
        // index key tells an attacker holding the database file nothing.
        ->and($email->fresh()?->address_hash)->not->toContain('lovelace');

    // The column is ciphertext, so the obvious wrong query finds nothing. This is the
    // assertion that would catch a call site reverting to `where('address', …)`.
    expect(ContactEmail::query()->where('address', SensitiveAttributeFixtures::SECOND_ADDRESS)->exists())->toBeFalse();
})->group('security');

it('fails closed with a comprehensible error when the application key is gone', function (): void {
    $connection = DB::connection();
    $ids = SensitiveAttributeFixtures::insert($connection);
    app(SensitiveAttributeRewriter::class)->encryptPlaintext();

    $plaintext = SensitiveAttributeFixtures::plaintext();

    // A keep-data uninstall that had *not* kept `core.app-key` would leave exactly
    // this: the database intact, a brand-new key, and the old one nowhere — not even
    // in the envelope's `previous[]`. That is A25 option (c), the one that was
    // rejected precisely because this is what it does.
    ApplicationKeys::with(ApplicationKeys::fresh(), [], function () use ($ids, $plaintext): void {
        foreach (SensitiveAttributeFixtures::inventoryRows() as ['model' => $model, 'table' => $table, 'column' => $column]) {
            $row = $model::query()->findOrFail($ids[$table]);

            try {
                $value = $row->{$column};
            } catch (UnreadableEncryptedAttributeException $exception) {
                // The message has to be usable by the person reading the log …
                expect($exception->getMessage())
                    ->toContain($model)
                    ->toContain($column)
                    ->toContain('core.app-key')
                    ->toContain('docs/operations/uninstall.md')
                    // … and must not carry the value, or a key, into a log line
                    // (ADR-003 D7; tests/Security/ExceptionRedactionTest.php).
                    ->and($exception->getMessage())->not->toContain($plaintext["{$table}.{$column}"])
                    ->and($exception->getMessage())->not->toContain('base64:')
                    ->and($exception->getPrevious())->toBeInstanceOf(DecryptException::class);

                continue;
            }

            // Reaching here is the failure this test exists to prevent: a read that
            // returned *something* under a key that cannot decrypt it.
            throw new RuntimeException(sprintf(
                '%s::$%s returned %s under a key that cannot decrypt it; it must fail closed instead.',
                $model,
                $column,
                var_export($value, true),
            ));
        }
    });
})->group('security');

it('still reads a row written under a previous key, and reports one no key can read', function (): void {
    $connection = DB::connection();
    $ids = SensitiveAttributeFixtures::insert($connection);

    // Both halves of what the S2-04 migration does, in its order: the blind index is
    // derived from the plaintext, then the plaintext is encrypted.
    app(SensitiveAttributeRewriter::class)->rewriteContactEmailHashes(onlyMissing: true);
    app(SensitiveAttributeRewriter::class)->encryptPlaintext();

    $original = (string) config('app.key');
    $rotated = ApplicationKeys::fresh();

    // The envelope after a rotation: a new `current` with the old key in `previous[]`
    // (ADR-003 D1, D8). Every row still reads — Evidence E35 — which is what makes the
    // re-encryption job safe to be *queued* rather than immediate.
    ApplicationKeys::with($rotated, [$original], function () use ($ids): void {
        expect(ContactEmail::query()->findOrFail($ids['contact_emails'])->address)->toBe(SensitiveAttributeFixtures::ADDRESS)
            // … and the blind index still finds it, because the lookup asks for the
            // hash under every key in the envelope, not only the current one.
            ->and(ContactEmail::query()->forAddress(SensitiveAttributeFixtures::ADDRESS)->firstOrFail()->id)->toBe($ids['contact_emails'])
            ->and(array_filter(app(SensitiveAttributeRewriter::class)->unreadable()))
            ->toBe([], 'nothing is unreadable while the old key is still in the envelope');
    });

    // Drop the old key without re-encrypting first and the rows become unreadable —
    // and the rewriter says so, column by column, instead of throwing somewhere in the
    // middle of a brief.
    ApplicationKeys::with($rotated, [], function (): void {
        $unreadable = array_filter(app(SensitiveAttributeRewriter::class)->unreadable());
        $columns = array_keys($unreadable);
        sort($columns);

        expect($columns)->toBe(EncryptedAttributeInventory::qualifiedColumns(), 'every inventoried column reports its row as unreadable')
            ->and(array_sum($unreadable))->toBe(count(EncryptedAttributeInventory::qualifiedColumns()));
    });
})->group('security');
