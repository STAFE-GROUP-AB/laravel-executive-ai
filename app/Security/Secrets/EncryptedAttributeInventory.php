<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use App\Models\AI\AiRun;
use App\Models\AI\Memory;
use App\Models\AI\MemorySource;
use App\Models\AI\ToolRun;
use App\Models\CRM\Commitment;
use App\Models\CRM\Communication;
use App\Models\CRM\Contact;
use App\Models\CRM\ContactEmail;
use App\Models\CRM\SourceRecord;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Model;

/**
 * The blueprint §3.1 sensitive attributes that are encrypted at rest (S2-04, #87),
 * and the only place the list is written down.
 *
 * It is a registry rather than a documentation table on purpose. The models do not
 * declare their own `encrypted:` casts: `App\Models\Concerns\EncryptsSensitiveAttributes`
 * reads this list, so the casts and ADR-003 D8's re-encryption job
 * (`App\Security\Secrets\AppKeyRotation`) are the *same* list and the job cannot fall
 * behind the casts by omission. `tests/Security/EncryptedAttributeInventoryTest.php`
 * closes the other direction: a hand-written `encrypted:` cast anywhere under
 * `app/Models/` fails the suite until it is entered here, which is what makes S2-04
 * the only item that can add one.
 *
 * **ADR-003 D1, Forbidden 1.** No entry here may be a token, password, key or
 * verifier: credentials live in the Keychain and no column holds one, encrypted or
 * not. Database rows carry `secret_service` / `secret_account` / `secret_version`
 * and nothing else credential-shaped, and the inventory test asserts that over the
 * column names with the same pattern the S2-03 contract test uses.
 *
 * **Why this list and not a longer one.** An `encrypted:` cast costs three things,
 * and each one excludes columns:
 *
 *  1. *An encrypted column cannot be searched.* The §3.1 search corpus (S2-01) is a
 *     FTS5 external-content table on the `local` profile and an InnoDB `FULLTEXT`
 *     index on `server`, over the columns `App\CRM\Search\SearchableEntity` names.
 *     Ciphertext tokenises to noise, so every column in that enum stays plaintext
 *     with its `sensitivity_class` recorded instead.
 *  2. *An encrypted column cannot be matched or indexed.* Laravel's encrypter is
 *     randomised — the same plaintext yields different ciphertext every time — so a
 *     unique index over ciphertext constrains nothing and an equality lookup never
 *     hits. A column that is matched therefore stays plaintext unless a keyed-hash
 *     column can carry the index instead, which is what `contact_emails.address_hash`
 *     does (the `passkey_credentials.credential_id_hash` pattern from #117).
 *  3. *Ciphertext is wider than plaintext.* A Laravel payload is base64 of a JSON
 *     envelope around the padded ciphertext, roughly 2.4× the input, and MySQL runs
 *     `STRICT_TRANS_TABLES` here, so an overflow is a hard error rather than a silent
 *     truncation (#117's lesson). Every column below is `mediumtext` on the `server`
 *     profile for that reason; on `local` SQLite neither enforces nor emits a length,
 *     so nothing changes there.
 *
 * The full argument, column by column — including the columns deliberately left
 * plaintext and why — is in `docs/architecture/data-model.md` §"Encryption at rest".
 */
final class EncryptedAttributeInventory
{
    /**
     * Model class => column => Eloquent cast.
     *
     * The cast is `encrypted` for a text column and `encrypted:array` for one that
     * held a JSON document before the cast: the cast encrypts the canonical JSON and
     * stores the ciphertext, which is why those columns stop being `json` columns on
     * the `server` profile — MySQL's `json` type rejects a base64 string outright.
     *
     * @return array<class-string<Model>, non-empty-array<string, string>>
     */
    public static function all(): array
    {
        return [
            // §3.2 "flera e-postadresser": the address is the strongest identifier a
            // natural person has in this schema, and it is only ever looked up by
            // exact match — so the ciphertext keeps the value and `address_hash`
            // keeps the `contact_emails_organization_address_unique` constraint.
            ContactEmail::class => ['address' => 'encrypted'],

            // `phones` is personal data that nothing matches on — the migration and
            // SearchableEntity both say so ("nothing matches on a phone number"), and
            // InnoDB cannot index a JSON column anyway.
            Contact::class => ['phones' => 'encrypted:array'],

            // The sender of a timeline item. Read whole and never matched: §5.6's
            // rule resolves an address *through* `contact_emails` (now through its
            // blind index), it never queries `communications.from_address`.
            Communication::class => ['from_address' => 'encrypted'],

            // The promise, quoted verbatim from a communication (§3.2). Confidential
            // by classification, text by type, no index.
            Commitment::class => ['text' => 'encrypted'],

            // The normalized snapshot of one external item. External content, keyed
            // by `source_records_external_unique` over ids and `content_hash` — never
            // by anything inside the payload.
            SourceRecord::class => ['payload' => 'encrypted:array'],

            // §4's app-owned memory and the evidence it rests on. `memories` is
            // `sensitivity_class = personal` whenever the memory is about a natural
            // person; the unique index is over `scope_key`/`memory_key`, not content.
            Memory::class => ['content' => 'encrypted'],
            MemorySource::class => ['excerpt' => 'encrypted'],

            // ADR-004 D7's prompt and response. Already redacted on write (ADR-003
            // D7 row 4); encryption is the second layer, because a redactor is a
            // pattern matcher and the prompt is still the owner's business.
            AiRun::class => ['prompt_redacted' => 'encrypted', 'response_redacted' => 'encrypted'],

            // The arguments a tool was called with and what came back (§1.2).
            ToolRun::class => ['arguments_redacted' => 'encrypted:array', 'result_redacted' => 'encrypted:array'],

            // ADR-006's Web Push subscription key material, which ADR-002 D9.5 and
            // the `push_subscriptions` migration both assign to this item by name.
            // Not a credential of ours under ADR-003 D1 — the browser handed them to
            // us and the VAPID private key is the Keychain item — but they are the
            // secret half of a subscription, so they are `confidential` and encrypted.
            PushSubscription::class => ['p256dh' => 'encrypted', 'auth' => 'encrypted'],
        ];
    }

    /**
     * The casts one model contributes, for `EncryptsSensitiveAttributes`.
     *
     * @param  class-string<Model>  $model
     * @return array<string, string>
     */
    public static function castsFor(string $model): array
    {
        return self::all()[$model] ?? [];
    }

    /**
     * Table => columns, for the migration that brings existing rows forward and for
     * the rotation job. Derived from the models rather than written twice, so a table
     * rename cannot leave the job pointed at a table that no longer exists.
     *
     * @return array<string, non-empty-list<string>>
     */
    public static function columnsByTable(): array
    {
        $tables = [];

        foreach (self::all() as $model => $casts) {
            $table = (new $model)->getTable();

            /** @var non-empty-list<string> $columns */
            $columns = [...($tables[$table] ?? []), ...array_keys($casts)];

            $tables[$table] = $columns;
        }

        return $tables;
    }

    /**
     * Every `<table>.<column>` in the inventory, sorted — the form the tests compare.
     *
     * @return list<string>
     */
    public static function qualifiedColumns(): array
    {
        $columns = [];

        foreach (self::columnsByTable() as $table => $names) {
            foreach ($names as $name) {
                $columns[] = "{$table}.{$name}";
            }
        }

        sort($columns);

        return $columns;
    }
}
