<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Providers\ModelCapabilities;
use App\AI\Providers\ProviderConnection;

/**
 * One (provider, model) pair the owner configured, with the capabilities the
 * registry resolved for it. The router filters over these; it never invents a
 * pair the owner did not configure, because substituting a model is exactly the
 * silent downgrade ADR-004 D3 forbids.
 */
final readonly class ConfiguredModel
{
    public function __construct(
        public ProviderConnection $provider,
        public string $modelId,
        public ModelCapabilities $capabilities,
    ) {}

    public function key(): string
    {
        return "{$this->provider->providerId}/{$this->modelId}";
    }
}
