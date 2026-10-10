<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * The body of GET /health/ready (health.schema.json `ready`), identical in
 * shape for 200 and 503.
 */
final readonly class ReadyDocument
{
    /** @param array<string, mixed> $body */
    public function __construct(public bool $ready, public array $body) {}

    public function statusCode(): int
    {
        return $this->ready ? 200 : 503;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->body;
    }
}
