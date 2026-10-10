<?php

declare(strict_types=1);

namespace App\Integrations\Mail\Exceptions;

use RuntimeException;

/**
 * `getMessage()` was asked for an id the provider no longer has (deleted between
 * sync and fetch, purged from Trash, or never belonged to this connection). Callers
 * record a tombstone; they never fall back to another connection (§5.6 criterion 4).
 * The id is included because provider message ids are opaque identifiers, not
 * content.
 */
final class EmailMessageNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $connectionId,
        public readonly string $externalId,
    ) {
        parent::__construct('The message is no longer available in this account.');
    }
}
