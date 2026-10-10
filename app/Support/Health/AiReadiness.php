<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * Source of `ai_ready`, `ai_state`, `ai_provider` and `ai_last_success_at`.
 * Health contract §3: ready only after a recorded successful completion
 * (`ai_runs`) with the currently selected provider; never from configuration.
 * The AI workstream rebinds this when `ai_runs` lands.
 */
interface AiReadiness
{
    public function report(): AiReadinessReport;
}
