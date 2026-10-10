<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Sprint 1 binding: there is no `ai_runs` table yet, so no completion can be
 * recorded and the install can never report AI ready. A selected provider
 * (config lea.ai.provider) yields configured_untested, nothing yields
 * not_configured. This is the §0 rule "AI setup required, never a false AI ready".
 */
final class ConfiguredProviderAiReadiness implements AiReadiness
{
    public function report(): AiReadinessReport
    {
        $provider = config('lea.ai.provider');

        if (! is_string($provider) || $provider === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider) !== 1) {
            return AiReadinessReport::notConfigured();
        }

        return AiReadinessReport::configuredUntested($provider);
    }
}
