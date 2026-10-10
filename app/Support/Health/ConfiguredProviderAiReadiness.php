<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Sprint 1 binding of AiReadiness (health contract §3; blueprint §0 "AI setup
 * required, never a false AI ready").
 *
 *  - no selected provider (config lea.ai.provider)            -> not_configured
 *  - a selected provider, no recorded success for it           -> configured_untested
 *  - a selected provider and RecordAiCompletionSuccess recorded
 *    a completion on that same provider                        -> ready
 *
 * Nothing else reaches `ready`: not a saved key, not a models list, not a health
 * probe. A success recorded for another provider does not count — changing the
 * provider resets `ai_ready` by construction. `error` is reported once Sprint 2
 * records failed runs; until then a failure leaves the state where it was.
 */
final class ConfiguredProviderAiReadiness implements AiReadiness
{
    public const string PROVIDER_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public function __construct(private readonly AiCompletionSuccessMarker $marker) {}

    public function report(): AiReadinessReport
    {
        $provider = config('lea.ai.provider');

        if (! is_string($provider) || preg_match(self::PROVIDER_ID_PATTERN, $provider) !== 1) {
            return AiReadinessReport::notConfigured();
        }

        $success = $this->marker->read();

        if ($success === null || $success->provider !== $provider) {
            return AiReadinessReport::configuredUntested($provider);
        }

        return AiReadinessReport::ready($provider, $success->at);
    }
}
