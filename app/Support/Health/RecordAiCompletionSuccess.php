<?php

declare(strict_types=1);

namespace App\Support\Health;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The only writer of the marker that moves `ai_state` to `ready` (health contract
 * §3; blueprint §0, §12.2 "AI-ready requires successful model completion").
 *
 * Call it from the AI run path and nowhere else: after `AiProviderAdapter::generate()`
 * or a completed `stream()` returned a usable response. Never from the installer,
 * never after a models list or a connectivity probe, never from a health check.
 * Sprint 2's run loop is the first caller; until it lands nothing calls this
 * class, so `ai_ready` is provably false in every install.
 */
final class RecordAiCompletionSuccess
{
    public function __construct(
        private readonly AiCompletionSuccessMarker $marker,
        private readonly HealthService $health,
    ) {}

    public function record(string $provider, string $model, ?CarbonImmutable $at = null): AiCompletionSuccess
    {
        if (preg_match(ConfiguredProviderAiReadiness::PROVIDER_ID_PATTERN, $provider) !== 1) {
            throw new InvalidArgumentException('A completion success needs the provider id it ran on.');
        }

        if (trim($model) === '' || mb_strlen($model) > 200) {
            throw new InvalidArgumentException('A completion success needs the model id it ran on.');
        }

        $success = new AiCompletionSuccess($provider, $model, $at ?? CarbonImmutable::now());

        $this->marker->record($success);
        $this->health->forgetCached();

        return $success;
    }

    /**
     * Changing provider resets `ai_ready` until a new completion succeeds (§3).
     */
    public function reset(): void
    {
        $this->marker->forget();
        $this->health->forgetCached();
    }
}
