<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * `K`, the 256-bit HMAC key from Keychain item `se.stafe.executive-ai.ipc.menubar`
 * (IPC contract §3.1), held in memory for the request only. Follows ADR-003
 * D3.4's SecretValue posture: it cannot be serialised, JSON-encoded, dumped
 * or cast to a string, and the bytes are zeroed when the object is destroyed.
 *
 * The all-zero test key (§4.6) can only be constructed through `forTesting()`,
 * which refuses outside the `testing` environment; `fromBytes()` refuses it
 * always, so a zero key written by mistake can never authenticate anything.
 */
final class IpcKey
{
    public const int LENGTH = 32;

    private string $bytes;

    private function __construct(#[\SensitiveParameter] string $bytes)
    {
        $this->bytes = $bytes;
    }

    public static function fromBytes(#[\SensitiveParameter] string $bytes): self
    {
        if (strlen($bytes) !== self::LENGTH) {
            throw new InvalidArgumentException('The IPC key must be exactly 32 bytes.');
        }

        if ($bytes === str_repeat("\0", self::LENGTH)) {
            throw new InvalidArgumentException('The all-zero IPC key is the test vector and is never a valid production key.');
        }

        return new self($bytes);
    }

    /**
     * `K_test` = 32 zero bytes (§4.6): every fixture in packaging/fixtures/ipc/
     * is signed with it. Loadable only while the application runs its tests.
     */
    public static function forTesting(): self
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('The IPC test key can only be loaded in the testing environment.');
        }

        return new self(str_repeat("\0", self::LENGTH));
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function __destruct()
    {
        $this->bytes = str_repeat("\0", strlen($this->bytes));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['bytes' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('An IPC key cannot be serialised.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An IPC key cannot be unserialised.');
    }

    public function __toString(): string
    {
        throw new LogicException('An IPC key cannot be cast to a string.');
    }

    public function __clone()
    {
        throw new LogicException('An IPC key cannot be cloned.');
    }
}
