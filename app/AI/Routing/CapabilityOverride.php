<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Providers\ModelCapability;

/**
 * The third and last source of ADR-004 D3's precedence: the owner asserting, in
 * Settings → Local Models, that a model the registry does not know can do
 * something. It is stored with a visible "unverified — may fail" marker
 * (`ai_models`, S2-02 #85) and can only ever *add* a capability the registry
 * left unknown — never contradict a registry `false`, which is the whole point
 * of the precedence rule.
 *
 * @see ModelCapabilityRegistry::resolve()
 */
final readonly class CapabilityOverride
{
    /**
     * @param  list<ModelCapability>  $capabilities  capabilities the owner asserts
     */
    public function __construct(
        public string $driver,
        public string $modelId,
        public array $capabilities = [],
        public ?int $contextWindow = null,
        public ?int $maxOutputTokens = null,
        public ?int $embeddingDimensions = null,
    ) {}

    public function key(): string
    {
        return "{$this->driver}/{$this->modelId}";
    }

    public function asserts(ModelCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
