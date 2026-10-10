<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Support\Health\AiState;
use Illuminate\Support\Facades\Lang;

/**
 * The only place the PWA turns `ai_state` into words (health contract §6,
 * blueprint §0, §1.2). The strings live in lang/{en,sv}/ai.php; "AI ready" is
 * the single key `ai.state.ready`, emitted on exactly one code path: the Ready
 * case of label(). Nothing here may say or imply that a model runs in the
 * browser or on a phone — AI and the scheduler run on the Mac (§1.2).
 */
final class AiStateCopy
{
    public static function label(AiState $state): string
    {
        return match ($state) {
            AiState::Ready => self::text('ai.state.ready'),
            AiState::Error => self::text('ai.state.error'),
            AiState::NotConfigured, AiState::ConfiguredUntested => self::text('ai.state.setup_required'),
        };
    }

    public static function tone(AiState $state): string
    {
        return match ($state) {
            AiState::Ready => 'success',
            AiState::Error => 'danger',
            AiState::NotConfigured, AiState::ConfiguredUntested => 'warning',
        };
    }

    public static function description(AiState $state): ?string
    {
        return match ($state) {
            AiState::Ready => null,
            AiState::Error => self::text('ai.description.error'),
            AiState::NotConfigured => self::text('ai.description.not_configured'),
            AiState::ConfiguredUntested => self::text('ai.description.configured_untested'),
        };
    }

    /**
     * The banner's affordance (§12.2 "Select AI banner"): opens the SELECT_AI
     * screen on the Mac — until that deep link exists, the PWA's AI settings page.
     */
    public static function actionLabel(AiState $state): ?string
    {
        return match ($state) {
            AiState::Ready => null,
            AiState::Error => self::text('ai.reconfigure'),
            AiState::NotConfigured, AiState::ConfiguredUntested => self::text('ai.select'),
        };
    }

    public static function showsBanner(AiState $state): bool
    {
        return $state !== AiState::Ready;
    }

    public static function badgeLabel(AiState $state): string
    {
        return self::text('ai.badge_label', ['label' => self::label($state)]);
    }

    /**
     * Display name of a provider id from lang/*\/ai.php, or the id itself when
     * the catalogue does not know it (never empty, never a guess).
     */
    public static function providerName(string $id): string
    {
        return Lang::has("ai.providers.{$id}") ? self::text("ai.providers.{$id}") : $id;
    }

    /** @param array<string, string> $replace */
    private static function text(string $key, array $replace = []): string
    {
        $value = __($key, $replace);

        return is_string($value) ? $value : $key;
    }
}
