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
    ) {}

    /**
     * @throws CapabilityUnavailableException no configured model can do the job
     * @throws EgressBlockedException `LOCAL_ONLY` and the only candidates are remote
     */
    public function resolve(JobRequirements $requirements, PrivacyMode $mode, ?DateTimeImmutable $now = null): RoutingDecision
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
            if (! $this->isEligibleUnder($candidate->provider, $mode, $now)) {
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

        return RoutingDecision::for($chosen, $mode, ...array_slice($eligible, 1));
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
     */
    public function isEligibleUnder(ProviderConnection $provider, PrivacyMode $mode, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable;

        return match ($mode) {
            PrivacyMode::LocalOnly => $this->isLoopbackLocal($provider),
            PrivacyMode::EuRestricted => ($provider->regionEvidence ?? RegionEvidence::none())->missingFor($now) === [],
            PrivacyMode::Custom => true,
        };
    }

    /**
     * A local driver whose host is loopback. The allowlist is literal — the
     * three loopback forms and nothing else — because a hostname that merely
     * looks local is not local (D4 layer 2; the resolve-and-pin SSRF guard and
     * the optional LAN opt-in are S2-14's).
     */
    private function isLoopbackLocal(ProviderConnection $provider): bool
    {
        if (! in_array($provider->driver, ['ollama', 'openai-compatible'], true)) {
            return false;
        }

        $host = $provider->host();

        if ($host === null) {
            return false;
        }

        $host = strtolower(trim($host, '[]'));

        return $host === 'localhost'
            || $host === '::1'
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
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
            $blocked = $candidates[0]->provider->host() ?? $candidates[0]->provider->providerId;

            throw new EgressBlockedException($blocked);
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
