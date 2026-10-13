<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\CRM\Commitment;
use App\Models\CRM\ContactEmail;
use App\Security\Authorization\Maintenance\MaintenanceActor;
use App\Security\Secrets\AppKeyRotation;
use App\Security\Secrets\EncryptedAttributeInventory;
use App\Security\Secrets\SensitiveAttributeRewriter;
use App\Security\Secrets\UnreadableEncryptedAttributeException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApplicationKeys;
use Tests\Support\SensitiveAttributeFixtures;

/*
 * ADR-003 **D8** "`APP_KEY` rotation": the `core.app-key` envelope holds `current` and
 * `previous[]`, Laravel decrypts with a previous key when the current one fails
 * (Evidence **E35**), and a re-encryption pass rewrites every `encrypted:` attribute. S2-04
 * (#87) is where that pass becomes real, so this is where the two halves are proven:
 *
 *  - a row written under a `previous[]` key still decrypts, before the pass runs at all;
 *  - after the pass, the same rows read with `previous[]` emptied — which is the only
 *    observable difference between "re-encrypted" and "still readable by luck".
 *
 * DatabaseTransactions, never the refresh-database trait (CLAUDE.md, ADR-002 D10).
 */

uses(DatabaseTransactions::class);

/** The fixture rows, encrypted and blind-indexed exactly as the S2-04 migration leaves them. */
function rowsUnderTheCurrentKey(): array
{
    $ids = SensitiveAttributeFixtures::insert(DB::connection());

    app(SensitiveAttributeRewriter::class)->rewriteContactEmailHashes(onlyMissing: true);
    app(SensitiveAttributeRewriter::class)->encryptPlaintext();

    return $ids;
}

it('re-encrypts every inventoried attribute under the new key', function (): void {
    $ids = rowsUnderTheCurrentKey();
    $plaintext = SensitiveAttributeFixtures::plaintext();
    $connection = DB::connection();

    $before = [];

    foreach (SensitiveAttributeFixtures::inventoryRows() as ['table' => $table, 'column' => $column]) {
        $before["{$table}.{$column}"] = (string) $connection->table($table)->where('id', $ids[$table])->value($column);
    }

    $old = (string) config('app.key');
    $new = ApplicationKeys::fresh();

    // The envelope immediately after the Supervisor wrote the rotation: new `current`,
    // old key kept in `previous[]`. Nothing has been rewritten yet.
    ApplicationKeys::with($new, [$old], function () use ($connection, $ids, $before, $plaintext): void {
        app(AppKeyRotation::class)->handle();

        foreach (SensitiveAttributeFixtures::inventoryRows() as ['table' => $table, 'column' => $column]) {
            $qualified = "{$table}.{$column}";
            $after = (string) $connection->table($table)->where('id', $ids[$table])->value($column);

            // The stored ciphertext changed — a rotation that wrote nothing would be
            // the silent failure here, and Eloquent's own dirty-checking is exactly
            // what would have caused it (see SensitiveAttributeRewriter).
            expect($after)->not->toBe($before[$qualified], "{$qualified} was rewritten")
                ->and(SensitiveAttributeRewriter::isCiphertext($after))->toBeTrue("{$qualified} is still a payload")
                // … and it still decrypts to the same bytes.
                ->and(SensitiveAttributeRewriter::plaintextOf($after))->toBe($plaintext[$qualified], "{$qualified} kept its value");
        }
    });

    // The proof that the rotation finished: drop the old key out of the envelope
    // entirely and every row still reads. Before the job ran, this is the state that
    // made every row unreadable (EncryptedAtRestTest).
    ApplicationKeys::with($new, [], function () use ($ids, $plaintext): void {
        expect(array_filter(app(SensitiveAttributeRewriter::class)->unreadable()))
            ->toBe([], 'nothing depends on the old key any more');

        foreach (SensitiveAttributeFixtures::inventoryRows() as ['model' => $model, 'table' => $table, 'column' => $column]) {
            $read = $model::query()->findOrFail($ids[$table])->{$column};
            $expected = $plaintext["{$table}.{$column}"];

            expect(is_string($read) ? $read : (string) json_encode($read, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->toBe($expected, "{$table}.{$column} reads under the new key alone");
        }
    });
})->group('security');

it('keeps a row written under a previous key readable before the rotation runs', function (): void {
    $ids = rowsUnderTheCurrentKey();

    $old = (string) config('app.key');
    $new = ApplicationKeys::fresh();

    // E35, stated as the property that matters operationally: the rotation may stay
    // pending for as long as it likes, because nothing is unreadable while the old key
    // is in the envelope. That is what makes a deferred re-encryption safe at all —
    // see AppKeyRotation on why it is not a ShouldQueue job (settled by #127).
    ApplicationKeys::with($new, [$old], function () use ($ids): void {
        expect(array_filter(app(SensitiveAttributeRewriter::class)->unreadable()))->toBe([])
            ->and(ContactEmail::query()->findOrFail($ids['contact_emails'])->address)->toBe(SensitiveAttributeFixtures::ADDRESS)
            // And the blind index too: its HMAC key is derived from the application
            // key, so every index key changed the moment the envelope did. The lookup
            // asks for the hash under each key in the envelope, so a half-finished
            // rotation leaves addresses findable rather than invisible.
            ->and(ContactEmail::query()->forAddress(SensitiveAttributeFixtures::ADDRESS)->firstOrFail()->id)
            ->toBe($ids['contact_emails']);
    });
})->group('security');

it('moves the blind index onto the new key so the lookup needs no fallback afterwards', function (): void {
    $ids = rowsUnderTheCurrentKey();

    $old = (string) config('app.key');
    $new = ApplicationKeys::fresh();

    ApplicationKeys::with($new, [$old], function (): void {
        app(AppKeyRotation::class)->handle();
    });

    ApplicationKeys::with($new, [], function () use ($ids): void {
        // With `previous[]` empty there is exactly one candidate hash, so this only
        // passes if the rotation rewrote `address_hash` as well as the ciphertext.
        $email = ContactEmail::query()->forAddress(SensitiveAttributeFixtures::ADDRESS)->firstOrFail();

        expect($email->id)->toBe($ids['contact_emails'])
            ->and($email->address_hash)->toBe(ContactEmail::hashFor(SensitiveAttributeFixtures::ADDRESS));
    });
})->group('security');

it('records the rotation in the audit trail with counts and never a value', function (): void {
    $ids = rowsUnderTheCurrentKey();
    $plaintext = SensitiveAttributeFixtures::plaintext();

    $old = (string) config('app.key');
    $new = ApplicationKeys::fresh();

    ApplicationKeys::with($new, [$old], function (): void {
        app(AppKeyRotation::class)->handle();
    });

    $event = AuditEvent::query()->where('action', 'secrets.app_key.reencrypted')->latest('occurred_at')->firstOrFail();
    $context = $event->context ?? [];
    $serialised = (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // #127: the actor is the tenant-wide maintenance shape, not `job:`, because
    // this repository also writes `job:` for pre-identity *user* actions
    // (`job:CreateOwnerForm`). `AuditCompletenessTest` owns the rest of that rule.
    expect($event->actor)->toBe(MaintenanceActor::PREFIX.'AppKeyRotation')
        ->and($event->outcome)->toBe('ok')
        ->and($context['attributes'] ?? null)->toBe(count(EncryptedAttributeInventory::qualifiedColumns()))
        ->and($context['rows_reencrypted'] ?? null)->toBe(count(EncryptedAttributeInventory::qualifiedColumns()))
        ->and($context['unreadable'] ?? null)->toBe([]);

    // §10, ADR-003 D7: the row says a rotation happened and how much it moved. It must
    // not contain a value it moved, and it must not contain a key.
    foreach ($plaintext as $value) {
        expect($serialised)->not->toContain($value);
    }

    expect($serialised)->not->toContain('base64:')
        ->and($serialised)->not->toContain($new)
        ->and($serialised)->not->toContain($old);

    unset($ids);
})->group('security');

it('reports a row no key can read instead of overwriting it', function (): void {
    $ids = rowsUnderTheCurrentKey();
    $installed = (string) config('app.key');

    // A value encrypted under a key that is simply gone — A25's rejected option (c),
    // or a database restored from a snapshot older than a rotation. Produced by
    // encrypting under a key that is then thrown away.
    $orphaned = null;

    ApplicationKeys::with(ApplicationKeys::fresh(), [], function () use (&$orphaned): void {
        $orphaned = app('encrypter')->encrypt('Ett åtagande som ingen nyckel kan läsa.', false);
    });

    ApplicationKeys::rebuild($installed, []);

    DB::table('commitments')->where('id', $ids['commitments'])->update(['text' => $orphaned]);

    app(AppKeyRotation::class)->handle();

    // Left exactly as it was: the job must not write a re-encrypted nothing over the
    // only evidence that a key was lost rather than rotated.
    expect((string) DB::table('commitments')->where('id', $ids['commitments'])->value('text'))->toBe($orphaned);

    $event = AuditEvent::query()->where('action', 'secrets.app_key.reencrypted')->latest('occurred_at')->firstOrFail();

    expect($event->outcome)->toBe('failed')
        ->and($event->context['unreadable']['commitments.text'] ?? null)->toBe(1);

    // And a read of it fails closed rather than handing back the ciphertext.
    expect(fn () => Commitment::query()->findOrFail($ids['commitments'])->text)
        ->toThrow(UnreadableEncryptedAttributeException::class);
})->group('security');
