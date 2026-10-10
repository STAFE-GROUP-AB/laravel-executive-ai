<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Support\Health\AiState;

/**
 * The only place the PWA turns `ai_state` into words (health contract §6,
 * blueprint §0, §1.2). "AI ready" exists on exactly one code path: the Ready
 * case. Nothing here may say or imply that a model runs in the browser or on
 * a phone — AI and the scheduler run on the Mac (§1.2).
 */
final class AiStateCopy
{
    public static function label(AiState $state): string
    {
        return match ($state) {
            AiState::Ready => 'AI ready',
            AiState::Error => 'AI error',
            AiState::NotConfigured, AiState::ConfiguredUntested => 'AI setup required',
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
            AiState::Error => 'The last AI run failed. Reconfigure AI on your Mac; your CRM, earlier briefs and the admin keep working meanwhile.',
            AiState::NotConfigured => 'No AI provider is selected yet. Your CRM, earlier briefs and the admin are available now. AI and the scheduler run on your Mac — choose a provider there to receive your first real brief.',
            AiState::ConfiguredUntested => 'A provider is selected, but no completion has succeeded yet, so AI is not ready. Finish the setup on your Mac; everything else keeps working.',
        };
    }

    public static function showsBanner(AiState $state): bool
    {
        return $state !== AiState::Ready;
    }
}
