<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Dto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Blueprint §5.6 `sync(): SyncBatch` — one page of changes for one scope.
 *
 * `messages` are new or changed messages (upserted on
 * `(connection_id, provider_message_id)`), `tombstones` the provider message ids
 * that were deleted or moved out of the synced scope. `nextCursor` is the position
 * *after* this page and must be committed in the same transaction as the rows
 * (ADR-002 D3.4); `hasMore` says whether the caller should request the next page
 * immediately (bootstrap) or wait for the next poll. A batch never contains more
 * items than the request asked for, and every message belongs to the connection
 * that was synced — criterion 4 is checked here, not only at the policy layer.
 */
final readonly class SyncBatch
{
    /**
     * @param  list<EmailMessage>  $messages
     * @param  list<string>  $tombstones  provider message ids that no longer exist in the scope
     */
    public function __construct(
        public string $connectionId,
        public SyncRequest $request,
        public array $messages,
        public array $tombstones,
        public SyncCursor $nextCursor,
        public bool $hasMore,
        public DateTimeImmutable $fetchedAt,
    ) {
        if (count($this->messages) + count($this->tombstones) > $this->request->limit) {
            throw new InvalidArgumentException('A sync batch may not contain more items than the request asked for.');
        }

        if ($this->nextCursor->isInitial()) {
            throw new InvalidArgumentException('A sync batch must advance the cursor; the initial cursor is a request, never a result.');
        }

        if ($this->nextCursor->scope !== $this->request->cursor->scope) {
            throw new InvalidArgumentException('The next cursor must cover the same scope as the request.');
        }

        foreach ($this->messages as $message) {
            if ($message->connectionId !== $this->connectionId) {
                throw new InvalidArgumentException('A sync batch may only carry messages of the connection it was fetched for (§5.6 criterion 4).');
            }
        }

        foreach ($this->tombstones as $tombstone) {
            if (trim($tombstone) === '') {
                throw new InvalidArgumentException('A tombstone names the provider message id that disappeared.');
            }
        }
    }

    public function count(): int
    {
        return count($this->messages) + count($this->tombstones);
    }

    public function isEmpty(): bool
    {
        return $this->messages === [] && $this->tombstones === [];
    }
}
