<?php

declare(strict_types=1);

namespace App\Security\Secrets;

/**
 * In-memory store for tests (ADR-003 D3.4). Nothing is persisted.
 */
final class ArraySecretStore implements SecretStore
{
    /** @var array<string, string> */
    private array $items = [];

    /** @param array<string, string> $items "<service>/<account>" => envelope */
    public function __construct(array $items = [])
    {
        foreach ($items as $key => $envelope) {
            $this->items[$key] = $envelope;
        }
    }

    public function get(SecretRef $ref): SecretValue
    {
        $envelope = $this->items[(string) $ref] ?? null;

        if ($envelope === null) {
            throw new SecretUnavailableException("not_found: {$ref}");
        }

        return new SecretValue($envelope);
    }

    public function put(SecretRef $ref, #[\SensitiveParameter] string $envelope): void
    {
        $this->items[(string) $ref] = $envelope;
    }

    public function delete(SecretRef $ref): void
    {
        unset($this->items[(string) $ref]);
    }

    public function list(string $service): array
    {
        $accounts = [];

        foreach (array_keys($this->items) as $key) {
            if (str_starts_with($key, $service.'/')) {
                $accounts[] = substr($key, strlen($service) + 1);
            }
        }

        sort($accounts);

        return $accounts;
    }
}
