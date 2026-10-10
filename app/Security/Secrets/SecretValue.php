<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use JsonException;
use JsonSerializable;
use LogicException;

/**
 * A secret in process memory for one request or job (ADR-003 D3.4): the
 * envelope the broker returned, as a string. It cannot be serialised,
 * JSON-encoded, cast, cloned or dumped, and the buffer is zeroed on destruct.
 */
final class SecretValue implements JsonSerializable
{
    private string $value;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        $this->value = $value;
    }

    /**
     * The raw envelope. Callers keep the result in a local and never in a
     * property that outlives the request.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * The envelope parsed as JSON.
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        try {
            $decoded = json_decode($this->value, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SecretUnavailableException('The secret envelope is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new SecretUnavailableException('The secret envelope is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Best-effort zeroing, the same posture as App\Security\Ipc\IpcKey: a typed property
     * cannot be handed to sodium_memzero() (it nulls its argument), so the buffer is
     * overwritten instead.
     */
    public function __destruct()
    {
        $this->value = str_repeat("\0", strlen($this->value));
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('A secret value cannot be serialised.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A secret value cannot be unserialised.');
    }

    public function __toString(): string
    {
        throw new LogicException('A secret value cannot be cast to a string.');
    }

    public function __clone()
    {
        throw new LogicException('A secret value cannot be cloned.');
    }

    /** @return never */
    public function jsonSerialize(): mixed
    {
        throw new LogicException('A secret value cannot be JSON-encoded.');
    }
}
