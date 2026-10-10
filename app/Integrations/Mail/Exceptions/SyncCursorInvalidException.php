<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

use App\Integrations\Mail\Dto\SyncCursor;
use RuntimeException;

/**
 * The provider no longer accepts the cursor and a full resync of `restartFrom`'s
 * scope is required (blueprint §5.6 "Provider-specifik inkrementell sync"):
 *
 * - Gmail: `users.history.list` answered 404 because `startHistoryId` is older than
 *   the history the mailbox retains.
 * - Microsoft Graph: the delta request answered `410 Gone` (`syncStateNotFound` /
 *   `resyncRequired`).
 * - IMAP: `UIDVALIDITY` of the mailbox differs from the one in the cursor, so every
 *   stored UID is meaningless (RFC 3501 §2.3.1.1; RFC 7162 for a stale MODSEQ).
 * - JMAP-style providers: `cannotCalculateChanges`.
 * - A cursor whose `kind` this connector does not own.
 *
 * `restartFrom` is `SyncCursor::initial()` for the same scope. The caller must not
 * drop existing rows: the bootstrap re-sync upserts on
 * `(connection_id, provider_message_id)` (ADR-002 D3) and emits tombstones for what
 * no longer exists.
 */
final class SyncCursorInvalidException extends RuntimeException
{
    public function __construct(
        public readonly string $connectionId,
        public readonly SyncCursor $rejected,
        public readonly SyncCursor $restartFrom,
        string $message = 'The sync position is no longer valid; a full resync of this folder is required.',
    ) {
        parent::__construct($message);
    }
}
