<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Security\Secrets\EncryptedAttributeInventory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One **plaintext** row per table in `EncryptedAttributeInventory`, plus the
 * prerequisite workspace rows their foreign keys need (S2-04, #87).
 *
 * The rows are inserted with the query builder, never through a model, which is the
 * whole point of the fixture: it reproduces the state a local install is actually in
 * before the S2-04 migration runs — every sensitive attribute readable in the
 * database file. `tests/Feature/Secrets/SensitiveAttributeRollForwardTest.php` writes
 * them to a throwaway database on the old shape and then runs the migration over them;
 * `tests/Security/EncryptedAtRestTest.php` writes them, encrypts them and reads them
 * back through the models.
 *
 * `plaintext()` is what every assertion compares against, so the expected value and
 * the inserted value cannot drift: both come from here.
 */
final class SensitiveAttributeFixtures
{
    /**
     * The two Web Push values are **structurally** real and **visibly** fake, and both
     * halves are deliberate.
     *
     * Structurally: `p256dh` is 87 base64url characters beginning `BE`, so it still
     * decodes to the 65-byte uncompressed P-256 point beginning `0x04` that RFC 8291
     * describes, and `auth` is 22 characters, so it still decodes to 16 bytes. That is
     * everything the tests here depend on — they assert that the *stored* column no
     * longer contains the value and that the value survives a round trip, neither of
     * which cares what the bytes mean.
     *
     * Visibly: the published example values are high-entropy (5.3 and 4.1 bits/char),
     * which is exactly what gitleaks' `generic-api-key` rule is built to find, and the
     * `secrets-scan` lane checks out with `fetch-depth: 0` and scans **every fetched
     * ref** — so a realistic-looking key in this file turns the lane red on unrelated
     * pull requests, before anything that could allowlist it has merged. These values
     * are 2.1 and 2.0 bits/char, well under the rule's 3.5 threshold, so the finding
     * does not exist rather than being excused after the fact. No `.gitleaks.toml`
     * entry is needed, which keeps that allowlist as narrow as its own first rule asks.
     */
    public const string ADDRESS = 'ada.lovelace@example.test';

    public const string SECOND_ADDRESS = 'a.lovelace@example.test';

    /**
     * The plaintext each inventoried column holds in `rows()`, keyed exactly as
     * `EncryptedAttributeInventory::qualifiedColumns()` is.
     *
     * The `encrypted:array` columns are compared as the JSON *string* that was
     * inserted: the migration treats a value as an opaque string and must hand it
     * back byte-identical, which is a stronger statement than "the decoded arrays
     * match".
     *
     * @return array<string, string>
     */
    public static function plaintext(): array
    {
        return [
            'contact_emails.address' => self::ADDRESS,
            'contacts.phones' => '[{"number":"+46 70 123 45 67","label":"mobile"}]',
            'communications.from_address' => 'enrico.ferrari@example.it',
            'commitments.text' => 'Vi skickar offerten på lagerlokalen i Mölndal före fredag.',
            'source_records.payload' => '{"title":"Rollout São Paulo","state":"open","number":212}',
            'memories.content' => 'Andreas vill ha briefen kort och med källa för varje påstående.',
            'memory_sources.excerpt' => 'Hej Andreas, vi behöver ert svar senast fredag.',
            'ai_runs.prompt_redacted' => "Sammanfatta veckans åtaganden.\nAnvänd källor.",
            'ai_runs.response_redacted' => 'Tre åtaganden förfaller denna vecka.',
            'tool_runs.arguments_redacted' => '{"customer_id":"01KFDA3QM02VDHVC2WGADBB9BV","limit":10}',
            'tool_runs.result_redacted' => '{"rows":3,"truncated":false}',
            'push_subscriptions.p256dh' => 'BEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKEF',
            'push_subscriptions.auth' => 'FAKEFAKEFAKEFAKEFAKEFA',
        ];
    }

    /**
     * Every row, in insertion order, as `[table => [column => value]]` pairs. The
     * workspace rows come first because the inventoried rows reference them.
     *
     * @return list<array{table: string, row: array<string, mixed>}>
     */
    public static function rows(): array
    {
        $organizationId = (string) Str::ulid();
        $userId = (string) Str::ulid();
        $customerId = (string) Str::ulid();
        $contactId = (string) Str::ulid();
        $communicationId = (string) Str::ulid();
        $memoryId = (string) Str::ulid();
        $plaintext = self::plaintext();

        $provenance = '{"origin":"import","actor":"job:SensitiveAttributeFixtures","recorded_at":"2026-10-13T06:00:00+00:00","trace_id":null}';
        $lineage = ['sensitivity_class' => 'personal', 'provenance' => $provenance];
        $internal = ['sensitivity_class' => 'internal', 'provenance' => $provenance];

        return [
            ['table' => 'organizations', 'row' => [
                'id' => $organizationId, 'name' => 'Example Organization', 'slug' => 'example-'.mb_substr($organizationId, -8),
                'timezone' => 'Europe/Stockholm', 'language' => 'sv', ...$internal,
            ]],
            ['table' => 'users', 'row' => [
                'id' => $userId, 'name' => 'Ada Owner', 'email' => 'owner+'.mb_substr($userId, -8).'@example.test',
                'created_at' => '2026-10-13 06:00:00', 'updated_at' => '2026-10-13 06:00:00',
            ]],
            ['table' => 'customers', 'row' => [
                'id' => $customerId, 'organization_id' => $organizationId, 'legal_name' => 'Ferrari Studio S.r.l.',
                'display_name' => 'Ferrari Studio', 'country' => 'IT', 'email_domains' => '[]', 'status' => 'active',
                'tags' => '[]', 'notes' => 'Studio in Milano.', ...$internal,
            ]],
            ['table' => 'contacts', 'row' => [
                'id' => $contactId, 'organization_id' => $organizationId, 'given_name' => 'Ada', 'family_name' => 'Lovelace',
                'display_name' => 'Ada Lovelace', 'title' => 'CTO', 'phones' => $plaintext['contacts.phones'],
                'language' => 'en', 'notes' => 'Prefers email.', ...$lineage,
            ]],
            ['table' => 'contact_emails', 'row' => [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'contact_id' => $contactId,
                'address' => $plaintext['contact_emails.address'], 'label' => 'work', 'is_primary' => true,
                'position' => 0, ...$lineage,
            ]],
            ['table' => 'communications', 'row' => [
                'id' => $communicationId, 'organization_id' => $organizationId, 'source_type' => 'email_message',
                'direction' => 'inbound', 'occurred_at' => '2026-10-12 08:15:00', 'subject' => 'Offert Mölndal',
                'excerpt' => 'Consegna prevista entro venerdì.', 'from_address' => $plaintext['communications.from_address'],
                'to_addresses' => '["owner@example.test"]', 'customer_id' => $customerId, 'contact_id' => $contactId,
                'match_reason' => 'Avsändaradressen är kontaktens primära adress.', 'matched_by' => 'rule',
                'confidence' => '0.950', ...$lineage,
            ]],
            ['table' => 'commitments', 'row' => [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'communication_id' => $communicationId,
                'customer_id' => $customerId, 'contact_id' => $contactId, 'direction' => 'owed_by_us',
                'text' => $plaintext['commitments.text'], 'promised_at' => '2026-10-12 08:20:00', 'status' => 'open',
                'extracted_by' => 'ai', 'sensitivity_class' => 'confidential', 'provenance' => $provenance,
            ]],
            ['table' => 'source_records', 'row' => [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'source_type' => 'github_pull_request',
                'external_account_id' => (string) Str::ulid(), 'external_id' => 'STAFE-GROUP-AB/laravel-executive-ai#212',
                'fetched_at' => '2026-10-13 05:40:00', 'occurred_at' => '2026-10-12 17:02:00',
                'content_hash' => 'sha256:'.str_repeat('a', 64), 'payload' => $plaintext['source_records.payload'], ...$internal,
            ]],
            ['table' => 'memories', 'row' => [
                'id' => $memoryId, 'organization_id' => $organizationId, 'scope' => 'organization',
                'scope_key' => 'organization', 'kind' => 'preference', 'memory_key' => 'brief.tone',
                'content' => $plaintext['memories.content'], 'status' => 'active', 'written_by' => 'user:'.$userId,
                'version' => 1, ...$lineage,
            ]],
            ['table' => 'memory_sources', 'row' => [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'memory_id' => $memoryId,
                'source_type' => 'communication', 'position' => 0, 'communication_id' => $communicationId,
                'excerpt' => $plaintext['memory_sources.excerpt'], 'occurred_at' => '2026-10-12 08:15:00', ...$lineage,
            ]],
            ['table' => 'ai_runs', 'row' => [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'trace_id' => (string) Str::ulid(),
                'job_class' => 'GenerateBrief', 'provider' => 'ollama', 'model_id' => 'llama3.2:3b',
                'privacy_mode' => 'local_only', 'prompt_hash' => 'sha256:'.str_repeat('b', 64),
                'capabilities_snapshot' => '{"text":true}', 'started_at' => '2026-10-13 05:00:00', 'outcome' => 'succeeded',
                'dedupe_key' => 'sha256:'.str_repeat('c', 64), 'dedupe_window_start' => '2026-10-13 05:00:00',
                'prompt_redacted' => $plaintext['ai_runs.prompt_redacted'],
                'response_redacted' => $plaintext['ai_runs.response_redacted'], ...$internal,
            ]],
            ['table' => 'tool_runs', 'row' => [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'trace_id' => (string) Str::ulid(),
                'tool_name' => 'crm.customers', 'tool_kind' => 'builtin', 'policy_decision' => 'allowed',
                'outcome' => 'succeeded', 'started_at' => '2026-10-13 05:00:01',
                'arguments_redacted' => $plaintext['tool_runs.arguments_redacted'],
                'result_redacted' => $plaintext['tool_runs.result_redacted'], ...$internal,
            ]],
            ['table' => 'push_subscriptions', 'row' => [
                'id' => (string) Str::ulid(), 'organization_id' => $organizationId, 'user_id' => $userId,
                'endpoint' => 'https://push.example.test/'.Str::ulid(), 'p256dh' => $plaintext['push_subscriptions.p256dh'],
                'auth' => $plaintext['push_subscriptions.auth'], 'content_encoding' => 'aes128gcm',
                'last_seen' => '2026-10-13 05:30:00', 'failure_count' => 0,
                'sensitivity_class' => 'confidential', 'provenance' => $provenance,
            ]],
        ];
    }

    /**
     * Inserts `rows()` on `$connection` and returns the row ids by table.
     *
     * @return array<string, string>
     */
    public static function insert(ConnectionInterface $connection): array
    {
        $ids = [];

        foreach (self::rows() as $entry) {
            $connection->table($entry['table'])->insert($entry['row']);

            $ids[$entry['table']] = (string) $entry['row']['id'];
        }

        return $ids;
    }

    /**
     * The tables the fixture writes to, newest dependency first — the order a cleanup
     * has to delete in, because every foreign key here is real.
     *
     * @return list<string>
     */
    public static function tablesInReverseDependencyOrder(): array
    {
        return array_reverse(array_column(self::rows(), 'table'));
    }

    /**
     * Guards against the fixture quietly covering less than the inventory: every
     * inventoried column must appear in `plaintext()`.
     */
    /**
     * The inventory flattened to one entry per attribute, so a test can walk it
     * without nesting two loops.
     *
     * @return list<array{model: class-string<Model>, table: string, column: string, cast: string}>
     */
    public static function inventoryRows(): array
    {
        $rows = [];

        foreach (EncryptedAttributeInventory::all() as $model => $casts) {
            $table = (new $model)->getTable();

            foreach ($casts as $column => $cast) {
                $rows[] = ['model' => $model, 'table' => $table, 'column' => $column, 'cast' => $cast];
            }
        }

        return $rows;
    }

    public static function coversTheWholeInventory(): bool
    {
        $covered = array_keys(self::plaintext());
        sort($covered);

        return $covered === EncryptedAttributeInventory::qualifiedColumns();
    }
}
