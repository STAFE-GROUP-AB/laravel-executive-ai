<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Providers\ModelCapabilities;
use App\AI\Providers\ProviderConnection;

/**
 * The one provider and the one model a job will run on (ADR-004 D3). Singular on
 * purpose: the decision is taken before the run and may not change during it, so
 * there is nowhere for a fallback to hide (D2 "no failover", threat model T-66).
 *
 * `alternatives` are the other eligible pairs, carried only so the UI can offer
 * the owner a different choice *before* the run starts. Nothing in the run path
 * may read them.
 */
final readonly class RoutingDecision
{
    /** @param list<ConfiguredModel> $alternatives */
    public function __construct(
        public ProviderConnection $provider,
        public string $modelId,
        public ModelCapabilities $capabilities,
        public PrivacyMode $privacyMode,
        public array $alternatives = [],
    ) {}

    public static function for(ConfiguredModel $model, PrivacyMode $mode, ConfiguredModel ...$alternatives): self
    {
        return new self(
            provider: $model->provider,
            modelId: $model->modelId,
            capabilities: $model->capabilities,
            privacyMode: $mode,
            alternatives: array_values($alternatives),
        );
    }
}
