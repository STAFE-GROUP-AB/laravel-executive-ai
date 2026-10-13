<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Providers\Exceptions\CapabilityUnavailableException;
use App\AI\Providers\Exceptions\ContextWindowExceededException;
use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Providers\Exceptions\RegionEvidenceMissingException;
use App\AI\Providers\ModelCapability;
use App\AI\Providers\ProviderConnection;
use App\AI\Providers\ProviderConnections;
use App\AI\Providers\RegionEvidence;
use App\Security\Egress\EgressGuard;
use App\Security\Egress\LoopbackHostResolver;
use App\Security\Privacy\ProviderEligibility;
use DateTimeImmutable;

/**
 * Capability-aware routing that fails closed (blueprint §4; ADR-004 D3).
 *
 * `resolve()` filters the models the owner configured by, in order: the
 * capabilities the job requires; eligibility under the privacy mode (D4's
 * loopback rule for `LOCAL_ONLY`, D5's evidence fields for `EU_RESTRICTED`); and
 * the context the prompt will occupy. What is left is a single decision. What is
 * left of nothing is a `CapabilityUnavailableException` naming the missing
 * capabilities — the job is refused, never degraded, and never retried on
 * another model (D3's forbidden downgrades, threat model T-67).
 *
 * Budgets, circuit breakers and concurrency are ADR-004 D7 and belong to the run
 * supervisor (S2-15); they narrow this set further and are deliberately not
 * decided here.
 */
final readonly class ModelRouter
{
    public function __construct(
        private ProviderConnections $connections,
        private ModelCapabilityRegistry $capabilities,
        private ProviderEligibility $eligibility = new ProviderEligibility(new EgressGuard(new LoopbackHostResolver)),
    ) {}

    /**
     * @throws CapabilityUnavailableException no configured model can do the job
     * @throws EgressBlockedException `LOCAL_ONLY` and the only candidates are remote
     */
    public function resolve(JobRequirements $requirements, PrivacyMode $mode, ?DateTimeImmutable $now = null, bool $allowLan = false): RoutingDecision
    {
        $now ??= new DateTimeImmutable;
        $candidates = $this->configuredModels();

        if ($candidates === []) {
            throw new CapabilityUnavailableException('(no model configured)', $requirements->capabilities);
        }

        $eligible = [];
        $missingPerModel = [];
        $tooSmall = [];

        foreach ($candidates as $candidate) {
            if (! $this->isEligibleUnder($candidate->provider, $mode, $now, $allowLan)) {
                continue;
            }

            $missing = $candidate->capabilities->missing($requirements->capabilities);

            if ($missing !== []) {
                $missingPerModel[$candidate->key()] = $missing;

                continue;
            }

            // A context overflow is a refusal with both token counts shown, never a
            // silent truncation to make the prompt fit (D3).
            if ($requirements->contextTokens > $candidate->capabilities->contextWindow) {
                $tooSmall[] = $candidate;

                continue;
            }

            $eligible[] = $candidate;
        }

        if ($eligible === []) {
            $this->refuse($requirements, $mode, $candidates, $missingPerModel, $tooSmall, $now);
        }

        $chosen = $eligible[0];

        return RoutingDecision::under($chosen, $mode, $allowLan, ...array_slice($eligible, 1));
    }

    /**
     * The configured (provider, model) pairs with their resolved capabilities,
     * in configuration order.
     *
     * @return list<ConfiguredModel>
     */
    public function configuredModels(): array
    {
        $models = [];

        foreach ($this->connections->all() as $connection) {
            foreach ($connection->modelIds as $modelId) {
                $models[] = new ConfiguredModel(
                    provider: $connection,
                    modelId: $modelId,
                    capabilities: $this->capabilities->resolve($connection->driver, $modelId, $connection->kind),
                );
            }
        }

        return $models;
    }

    /**
     * ADR-004 D4 layer 1 and D5: in `LOCAL_ONLY` only a local driver on a
     * loopback host may be built at all; in `EU_RESTRICTED` the provider must
     * carry every evidence field, unexpired. The modes compose as a conjunction
     * when both are set.
     *
     * S2-13 answered the `LOCAL_ONLY` half here, with a literal allowlist of
     * the three loopback spellings and a comment saying the resolve-and-pin
     * guard was S2-14's. It is: both halves now live in
     * `App\Security\Privacy\ProviderEligibility`, so there is one
     * implementation of each rule rather than one per caller.
     *
     * `$allowLan` defaults to false — the strictest reading — so a caller that
     * does not hold the stored policy can never widen the boundary by omission.
     * The LAN opt-in is honoured only where the policy is actually read
     * (`AiServiceProvider`, `EgressServiceProvider`'s middleware).
     */
    public function isEligibleUnder(ProviderConnection $provider, PrivacyMode $mode, ?DateTimeImmutable $now = null, bool $allowLan = false): bool
    {
        return $this->eligibility->allows($provider, $mode, $allowLan, $now ?? new DateTimeImmutable);
    }

    /**
     * Fail closed with the reason the owner can actually act on, and never with
     * a reason that misdescribes what happened: a prompt too large for the
     * window is a context refusal, a provider short of D5 evidence is an
     * evidence refusal, a remote provider under Local Only is blocked egress,
     * and only a genuine capability gap is reported as one.
     *
     * @param  list<ConfiguredModel>  $candidates
     * @param  array<string, list<ModelCapability>>  $missingPerModel
     * @param  list<ConfiguredModel>  $tooSmall
     *
     * @throws CapabilityUnavailableException
     * @throws ContextWindowExceededException
     * @throws EgressBlockedException
     * @throws RegionEvidenceMissingException
     */
    private function refuse(
        JobRequirements $requirements,
        PrivacyMode $mode,
        array $candidates,
        array $missingPerModel,
        array $tooSmall,
        DateTimeImmutable $now,
    ): never {
        if ($tooSmall !== []) {
            throw new ContextWindowExceededException(
                modelId: $tooSmall[0]->modelId,
                contextTokens: $requirements->contextTokens,
                contextWindow: $tooSmall[0]->capabilities->contextWindow,
            );
        }

        if ($missingPerModel === [] && $mode === PrivacyMode::LocalOnly) {
            $provider = $candidates[0]->provider;

            throw new EgressBlockedException(
                blockedHost: $provider->host() ?? $provider->providerId,
                rule: $this->eligibility->refusalFor($provider, $mode),
                reason: 'Local Only admits only a local driver on a loopback endpoint',
            );
        }

        if ($missingPerModel === [] && $mode === PrivacyMode::EuRestricted) {
            $provider = $candidates[0]->provider;

            throw new RegionEvidenceMissingException(
                providerId: $provider->providerId,
                missingFields: ($provider->regionEvidence ?? RegionEvidence::none())->missingFor($now),
            );
        }

        if ($missingPerModel === []) {
            throw new CapabilityUnavailableException(
                $this->describeCandidates($candidates),
                $requirements->capabilities,
            );
        }

        $missing = [];

        foreach ($missingPerModel as $perModel) {
            foreach ($perModel as $capability) {
                $missing[$capability->value] = $capability;
            }
        }

        throw new CapabilityUnavailableException($this->describeCandidates($candidates), array_values($missing));
    }

    /**
     * @param  list<ConfiguredModel>  $candidates
     */
    private function describeCandidates(array $candidates): string
    {
        return implode(', ', array_map(
            static fn (ConfiguredModel $model): string => $model->key(),
            $candidates,
        ));
    }
}
