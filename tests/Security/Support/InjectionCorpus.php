<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Loader for the frozen S0-19 adversarial corpus and for the S0-18 world the
 * payloads ride on (`tests/Security/README.md` → "Where the data lives").
 *
 * The suites never invent an attack inline: they name a case id, and this loader
 * resolves it, validates it against the shape `injection.schema.json` guarantees,
 * and resolves `context.ref` into the frozen CRM record the payload occupies
 * (contract rules 3 and 5 — the payload is injected through its carrier, never
 * pasted into the user's turn).
 *
 * Every failure here is a `RuntimeException`, not a skipped assertion: a renamed
 * field or a deleted case must break the build loudly (contract rule 8).
 */
final class InjectionCorpus
{
    /**
     * The frozen fixture clock (contract rule 6). Never read the wall clock.
     */
    public const string FIXTURE_NOW = '2026-10-09T12:00:00+02:00';

    private const string CORPUS_DIRECTORY = 'packaging/fixtures/security/prompt-injection';

    private const string CRM_DIRECTORY = 'packaging/fixtures/crm';

    /**
     * The `email_body` carrier file; the §16.7 cases all live here.
     */
    private const string CARRIER_FILE = 'email-body.json';

    /**
     * Pest dataset keyed by case id, so a failure names the attack
     * (`tests/Security/README.md`: "one test per case id, named after the case").
     *
     * @return array<string, array{InjectionCase}>
     */
    public static function dataset(string ...$ids): array
    {
        $dataset = [];

        foreach ($ids as $id) {
            $dataset[$id] = [self::case($id)];
        }

        return $dataset;
    }

    public static function case(string $id): InjectionCase
    {
        foreach (self::rows(self::CARRIER_FILE) as $row) {
            if (($row['id'] ?? null) === $id) {
                return self::hydrate($row);
            }
        }

        throw new RuntimeException(sprintf(
            'Injection case %s is not in %s/%s. A case is frozen data: it is never renamed or deleted to make a suite green (tests/Security/README.md contract rule 8).',
            $id,
            self::CORPUS_DIRECTORY,
            self::CARRIER_FILE,
        ));
    }

    /**
     * The hostile email as it arrives, riding on the S0-18 record `context.ref`
     * names. Resolving the reference here is what keeps the test honest: an invented
     * id cannot be substituted for the frozen world (contract rule 3).
     */
    public static function arrivalFor(InjectionCase $case): ForeignEmailArrival
    {
        if ($case->ridesOn !== 'crm_communication') {
            throw new RuntimeException(sprintf(
                'Case %s rides on `%s`; this harness injects through `crm_communication` only.',
                $case->id,
                $case->ridesOn,
            ));
        }

        $communication = self::communication($case->ref);

        if (self::string($communication, 'account_id') !== (string) $case->accountId) {
            throw new RuntimeException(sprintf(
                'Case %s arrives through account %s but communication %s belongs to account %s (§5.6 multi-account isolation).',
                $case->id,
                (string) $case->accountId,
                $case->ref,
                self::string($communication, 'account_id'),
            ));
        }

        return ForeignEmailArrival::fromFixtures(
            case: $case,
            organizationId: self::string($communication, 'organization_id'),
            subject: self::string($communication, 'subject'),
            fromAddress: self::string($communication, 'from_address'),
            ownerAddress: self::firstRecipient($communication, $case->ref),
            customerId: self::string($communication, 'customer_id'),
            contactId: self::string($communication, 'contact_id'),
        );
    }

    /**
     * Every customer in the frozen world, `id => [display name, legal name]`. The
     * exfiltration case asks for "hela kundlistan"; the interpreter uses this to
     * assert that no customer other than the one the carrier belongs to appears in
     * output (`cross_customer_data_in_output`).
     *
     * @return array<string, list<string>>
     */
    public static function customerNames(): array
    {
        $names = [];

        foreach (self::decode(self::path(self::CRM_DIRECTORY, 'customers.json')) as $row) {
            $names[self::string($row, 'id')] = [
                self::string($row, 'display_name'),
                self::string($row, 'legal_name'),
            ];
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public static function communication(string $id): array
    {
        foreach (self::decode(self::path(self::CRM_DIRECTORY, 'communications.json')) as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        throw new RuntimeException(sprintf(
            'Communication %s is not in %s/communications.json; `context.ref` must resolve into the frozen S0-18 world.',
            $id,
            self::CRM_DIRECTORY,
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function hydrate(array $row): InjectionCase
    {
        $context = $row['context'] ?? null;
        $expected = $row['expected'] ?? null;

        if (! is_array($context) || ! is_array($expected)) {
            throw new RuntimeException(sprintf('Case %s is missing `context` or `expected`.', self::string($row, 'id')));
        }

        $carrier = self::string($row, 'carrier');

        if ($carrier !== 'email_body') {
            throw new RuntimeException(sprintf(
                'Case %s has carrier `%s`; this suite asserts the email-body boundary only.',
                self::string($row, 'id'),
                $carrier,
            ));
        }

        $accountId = $context['account_id'] ?? null;

        if ($accountId !== null && ! is_string($accountId)) {
            throw new RuntimeException(sprintf('Case %s has a non-string `context.account_id`.', self::string($row, 'id')));
        }

        $userMessageKey = $expected['user_message_key'] ?? null;

        if ($userMessageKey !== null && ! is_string($userMessageKey)) {
            throw new RuntimeException(sprintf('Case %s has a non-string `expected.user_message_key`.', self::string($row, 'id')));
        }

        $case = new InjectionCase(
            id: self::string($row, 'id'),
            carrier: $carrier,
            language: self::string($row, 'language'),
            attackClass: self::string($row, 'attack_class'),
            encoding: self::strings($row, 'encoding'),
            title: self::string($row, 'title'),
            payload: self::string($row, 'payload'),
            ridesOn: self::string($context, 'rides_on'),
            ref: self::string($context, 'ref'),
            field: self::string($context, 'field'),
            accountId: $accountId,
            arrivesAt: new DateTimeImmutable(self::string($context, 'arrives_at')),
            mustNot: self::strings($expected, 'must_not'),
            must: self::strings($expected, 'must'),
            allowedOutputMentions: self::strings($expected, 'allowed_output_mentions'),
            userMessageKey: $userMessageKey,
            references: self::strings($row, 'references'),
            notes: self::string($row, 'notes'),
        );

        if ($case->mustNot === []) {
            throw new RuntimeException(sprintf('Case %s has no forbidden effect, so it is not an attack.', $case->id));
        }

        if ($case->arrivesAt > new DateTimeImmutable(self::FIXTURE_NOW)) {
            throw new RuntimeException(sprintf(
                'Case %s arrives after the frozen fixture clock %s (contract rule 6).',
                $case->id,
                self::FIXTURE_NOW,
            ));
        }

        return $case;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(string $file): array
    {
        return self::decode(self::path(self::CORPUS_DIRECTORY, $file));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decode(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Fixture %s is missing.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Fixture %s could not be read.', $path));
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Fixture %s is not valid JSON.', $path), 0, $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new RuntimeException(sprintf('Fixture %s must be a JSON array of records.', $path));
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                throw new RuntimeException(sprintf('Fixture %s must contain objects only.', $path));
            }

            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Fixture field `%s` must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<string>
     */
    private static function strings(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(sprintf('Fixture field `%s` must be a JSON array.', $key));
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException(sprintf('Fixture field `%s` must contain strings only.', $key));
            }

            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * @param  array<string, mixed>  $communication
     */
    private static function firstRecipient(array $communication, string $id): string
    {
        $recipients = self::strings($communication, 'to_addresses');

        if ($recipients === []) {
            throw new RuntimeException(sprintf('Communication %s has no recipient, so the owner identity is unknown.', $id));
        }

        return $recipients[0];
    }

    private static function path(string $directory, string $file): string
    {
        return dirname(__DIR__, 3).'/'.$directory.'/'.$file;
    }
}
