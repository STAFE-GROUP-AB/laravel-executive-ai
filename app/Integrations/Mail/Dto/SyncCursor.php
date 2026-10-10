<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Blueprint §5.6 `sync(EmailConnection, SyncCursor)`: the resumable position of one
 * sync scope, persisted in `email_sync_cursors` and committed in the same
 * transaction as the batch it covers (ADR-002 D3.4).
 *
 * The cursor is **opaque to everyone but the connector that issued it**. The
 * interface has no provider branch: a cursor is `kind` (a namespaced token the
 * connector owns), `scope` (the mailbox/folder the position applies to, or `*` for
 * the whole account), `value` (the provider's own token, verbatim), optional
 * structured `parts` the connector finds convenient, `issuedAt`, and a `version`
 * that lets an adapter re-encode old cursors. The connector validates and parses
 * `value`/`parts` itself and throws `SyncCursorInvalidException` for a cursor it
 * does not own or the provider no longer accepts. Nothing outside the adapter reads
 * `value`.
 *
 * Mappings (**provisional until verified against each vendor SDK**; what is
 * verified here is vendor documentation from memory, not an executed call):
 *
 * | Provider | `kind` | `scope` | `value` | `parts` |
 * |---|---|---|---|---|
 * | Gmail (`users.history.list`, `startHistoryId`) | `gmail.history` | `*` — history ids are per mailbox, not per label | the `historyId` as a decimal string (opaque uint64; never compared numerically across mailboxes) | — |
 * | Microsoft Graph (`/me/mailFolders/{id}/messages/delta`) | `graph.delta` | the mail folder id — delta state is **per folder** | the full `@odata.deltaLink` URL (complete pass) or `@odata.nextLink` URL (mid-pass) returned by Graph, verbatim; Graph says to persist the whole link, not the `$deltatoken`/`$skiptoken` alone | `link` → `delta` or `next` |
 * | IMAP (RFC 3501 UID, RFC 7162 CONDSTORE/QRESYNC) | `imap.qresync` (or `imap.uid` when the server lacks CONDSTORE) | the mailbox name as the server reports it (modified UTF-7) | `"<uidvalidity>:<uid>:<modseq>"`, canonical | `uidvalidity` → int, `uid` → int (highest UID seen), `modseq` → int (HIGHESTMODSEQ; `0` without CONDSTORE) |
 * | JMAP-style (Fastmail, possibly HEY) | `jmap.state` | the JMAP account id | the `Email/changes` state string | — |
 * | HEY CLI | `hey.cli` | per verification | per verification (§5.3: check the installed CLI before shipping) | — |
 * | Proton Bridge | the IMAP kinds above | the Bridge mailbox | as IMAP | as IMAP |
 *
 * Expiry is not a cursor state but the connector's verdict: Gmail 404 on an old
 * `startHistoryId`, Graph `410 Gone`, an IMAP `UIDVALIDITY` change or JMAP
 * `cannotCalculateChanges` make the connector throw `SyncCursorInvalidException`
 * carrying `SyncCursor::initial($scope)` as the restart point.
 */
final readonly class SyncCursor
{
    public const string INITIAL_KIND = 'initial';

    public const string ACCOUNT_SCOPE = '*';

    public const int MAX_VALUE_LENGTH = 16384;

    /**
     * @param  array<string, int|string>  $parts
     */
    public function __construct(
        public string $kind,
        public string $scope,
        public string $value,
        public array $parts = [],
        public ?DateTimeImmutable $issuedAt = null,
        public int $version = 1,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/', $this->kind) !== 1) {
            throw new InvalidArgumentException('A cursor kind is a lower-case, dot-namespaced token owned by one connector (e.g. `gmail.history`).');
        }

        if ($this->scope === '' || preg_match('/[\r\n\x00]/', $this->scope) === 1) {
            throw new InvalidArgumentException('A cursor scope is a folder id, a mailbox name or `*`, never empty.');
        }

        if ($this->kind === self::INITIAL_KIND && $this->value !== '') {
            throw new InvalidArgumentException('The initial cursor carries no value.');
        }

        if ($this->kind !== self::INITIAL_KIND && $this->value === '') {
            throw new InvalidArgumentException('A non-initial cursor must carry the provider\'s position.');
        }

        if (strlen($this->value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException('A cursor value may not exceed '.self::MAX_VALUE_LENGTH.' bytes.');
        }

        foreach (array_keys($this->parts) as $key) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $key) !== 1) {
                throw new InvalidArgumentException('Cursor part keys are lower-case snake_case strings.');
            }
        }

        if ($this->version < 1) {
            throw new InvalidArgumentException('A cursor version starts at 1.');
        }
    }

    /**
     * "Nothing synced yet for this scope": the bootstrap position every connector
     * must accept and the restart point after `SyncCursorInvalidException`.
     */
    public static function initial(string $scope = self::ACCOUNT_SCOPE): self
    {
        return new self(self::INITIAL_KIND, $scope, '');
    }

    public function isInitial(): bool
    {
        return $this->kind === self::INITIAL_KIND;
    }

    /**
     * Whether this cursor was issued by a connector that owns `$kindPrefix`
     * (`gmail`, `graph`, `imap`, ...). The connector calls this first and throws
     * `SyncCursorInvalidException` when false; the initial cursor belongs to everyone.
     */
    public function belongsTo(string $kindPrefix): bool
    {
        return $this->isInitial() || $this->kind === $kindPrefix || str_starts_with($this->kind, $kindPrefix.'.');
    }

    public function part(string $key): int|string|null
    {
        return $this->parts[$key] ?? null;
    }

    /**
     * Canonical persistence form for `email_sync_cursors.value`: JSON with sorted
     * keys so two equal cursors are byte-equal.
     */
    public function encode(): string
    {
        $parts = $this->parts;
        ksort($parts);

        $payload = [
            'issued_at' => $this->issuedAt?->format(DATE_RFC3339_EXTENDED),
            'kind' => $this->kind,
            'parts' => $parts === [] ? null : $parts,
            'scope' => $this->scope,
            'value' => $this->value,
            'version' => $this->version,
        ];

        return json_encode(array_filter($payload, fn (mixed $v): bool => $v !== null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function decode(string $encoded): self
    {
        try {
            $payload = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A persisted cursor must be a JSON object.', 0, $exception);
        }

        if (! is_array($payload) || ! is_string($payload['kind'] ?? null) || ! is_string($payload['scope'] ?? null) || ! is_string($payload['value'] ?? null)) {
            throw new InvalidArgumentException('A persisted cursor must carry string `kind`, `scope` and `value`.');
        }

        $parts = $payload['parts'] ?? [];

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Cursor parts must be an object.');
        }

        foreach ($parts as $key => $part) {
            if (! is_string($key) || (! is_int($part) && ! is_string($part))) {
                throw new InvalidArgumentException('Cursor parts are string-keyed ints or strings.');
            }
        }

        $issuedAt = $payload['issued_at'] ?? null;

        if ($issuedAt !== null && ! is_string($issuedAt)) {
            throw new InvalidArgumentException('A cursor `issued_at` must be an RFC 3339 string.');
        }

        $version = $payload['version'] ?? 1;

        if (! is_int($version)) {
            throw new InvalidArgumentException('A cursor version must be an integer.');
        }

        /** @var array<string, int|string> $parts */
        return new self(
            kind: $payload['kind'],
            scope: $payload['scope'],
            value: $payload['value'],
            parts: $parts,
            issuedAt: $issuedAt === null ? null : new DateTimeImmutable($issuedAt),
            version: $version,
        );
    }

    public function equals(self $other): bool
    {
        return $this->encode() === $other->encode();
    }
}
