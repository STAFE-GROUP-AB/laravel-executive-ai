<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Providers\ModelCapability;

/**
 * The second source of ADR-004 D3's precedence: what a local runtime reports
 * about a model it has installed — Ollama's `POST /api/show` `capabilities`
 * array, LM Studio's `GET /api/v0/models` `type` and `max_context_length`.
 *
 * A probe **fills fields the registry left null; it never overrides a registry
 * `false`** (D3). That asymmetry is enforced in `ModelCapabilityRegistry` and
 * has its own test, because the opposite would let a runtime talk the product
 * into a capability nobody verified.
 */
final readonly class ProbedCapabilities
{
    /**
     * @param  list<ModelCapability>  $capabilities  what the runtime reports it can do
     */
    public function __construct(
        public string $driver,
        public string $modelId,
        public array $capabilities = [],
        public ?int $contextWindow = null,
        public ?int $maxOutputTokens = null,
        public ?int $embeddingDimensions = null,
        public ?string $quantization = null,
        public ?string $digest = null,
    ) {}

    public function key(): string
    {
        return "{$this->driver}/{$this->modelId}";
    }

    public function reports(ModelCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
