<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Providers\Exceptions\ProviderUnavailableException;
use App\AI\Routing\ModelRouter;
use App\AI\Routing\PrivacyMode;
use App\AI\Routing\RoutingDecision;
use App\Security\Egress\EgressGuard;
use App\Security\Egress\LoopbackHostResolver;
use App\Security\Privacy\ProviderEligibility;

/**
 * Builds adapters from the configured providers (ADR-004 D2), and is the first
 * of D4's three Local Only layers: **in `LOCAL_ONLY` a non-local provider is
 * never instantiated at all**. Not built, not callable, shown greyed out as
 * "disabled by Local Only" — so there is no object on which a mistake could
 * later make a cloud call. The HTTP-layer veto and the resolve-and-pin SSRF
 * guard are the next layers and landed in S2-14 as `App\Security\Egress`;
 * this one holds even if they are absent, and each layer has its own test.
 * The address rule itself is not restated here — `ProviderEligibility` is the
 * one implementation, and the refusal carries the `EgressRule` that fired.
 *
 * Adapters are memoised per provider so a run reuses one instance, and the key
 * is read from the Keychain inside the adapter at call time rather than cached
 * here (ADR-003).
 */
final class ProviderRegistry
{
    /** @var array<string, AiProviderAdapter> */
    private array $adapters = [];

    public function __construct(
        private readonly ProviderConnections $connections,
        private readonly AdapterFactory $factory,
        private readonly ModelRouter $router,
        private readonly ProviderEligibility $eligibility = new ProviderEligibility(new EgressGuard(new LoopbackHostResolver)),
    ) {}

    /**
     * The adapter for one configured provider.
     *
     * @throws EgressBlockedException the privacy mode forbids building it
     * @throws ProviderUnavailableException the owner has not configured this provider
     */
    public function adapterFor(string $providerId, PrivacyMode $mode, bool $allowLan = false): AiProviderAdapter
    {
        $connection = $this->connections->find($providerId);

        if ($connection === null) {
            throw new ProviderUnavailableException(new AiError(
                kind: AiErrorKind::Connection,
                message: "No provider is configured under [{$providerId}].",
                retryable: false,
            ));
        }

        if (! $this->router->isEligibleUnder($connection, $mode, null, $allowLan)) {
            throw new EgressBlockedException(
                blockedHost: $connection->host() ?? $providerId,
                rule: $this->eligibility->refusalFor($connection, $mode, $allowLan),
                reason: 'the privacy mode in force does not admit this provider',
            );
        }

        return $this->adapters[$providerId] ??= $this->factory->make($connection);
    }

    /**
     * The adapter a routing decision named. The decision already applied the
     * capability and privacy filters, so this is the one call site a run uses.
     */
    public function adapterForDecision(RoutingDecision $decision): AiProviderAdapter
    {
        return $this->adapterFor($decision->provider->providerId, $decision->privacyMode, $decision->allowLan);
    }

    /**
     * Whether this provider may be built under the mode. The SELECT_AI screen
     * and `/settings/ai` use it to explain why a configured provider is
     * unavailable instead of offering it and failing later.
     */
    public function isBuildable(string $providerId, PrivacyMode $mode, bool $allowLan = false): bool
    {
        $connection = $this->connections->find($providerId);

        return $connection !== null && $this->router->isEligibleUnder($connection, $mode, null, $allowLan);
    }

    /**
     * Every configured provider the mode allows, in configuration order.
     *
     * @return list<ProviderConnection>
     */
    public function buildable(PrivacyMode $mode, bool $allowLan = false): array
    {
        return array_values(array_filter(
            $this->connections->all(),
            fn (ProviderConnection $connection): bool => $this->router->isEligibleUnder($connection, $mode, null, $allowLan),
        ));
    }

    /**
     * Every configured provider the mode excludes, so the UI can list them as
     * disabled with a reason rather than hiding them (D4 layer 1).
     *
     * @return list<ProviderConnection>
     */
    public function disabledBy(PrivacyMode $mode, bool $allowLan = false): array
    {
        return array_values(array_filter(
            $this->connections->all(),
            fn (ProviderConnection $connection): bool => ! $this->router->isEligibleUnder($connection, $mode, null, $allowLan),
        ));
    }
}
