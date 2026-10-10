<?php

declare(strict_types=1);

namespace App\Security\Ipc\OAuth;

use InvalidArgumentException;

/**
 * §6.6 response data. `status` is `connected`, `pending` (exchange still
 * running after the 15 s budget) or `failed` (with a sanitised
 * `errorReason` category, never provider prose). `openPath` is the PWA
 * path the client opens; the controller prefixes the loopback origin.
 */
final readonly class OAuthCallbackResult
{
    public function __construct(
        public string $status,
        public ?string $connectionId,
        public ?string $provider,
        public ?string $openPath,
        public ?string $errorReason = null,
    ) {
        if (! in_array($status, ['connected', 'pending', 'failed'], true)) {
            throw new InvalidArgumentException("Unknown OAuth callback status `{$status}`.");
        }

        if ($status === 'failed' && ($errorReason === null || preg_match('/^[a-z][a-z0-9_]{1,63}$/', $errorReason) !== 1)) {
            throw new InvalidArgumentException('A failed OAuth callback needs a sanitised error_reason category.');
        }

        if ($status !== 'failed' && $errorReason !== null) {
            throw new InvalidArgumentException('error_reason is only set when the callback failed.');
        }
    }
}
