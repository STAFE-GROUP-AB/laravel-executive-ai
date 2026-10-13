<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\Dto\AiUsage;
use App\AI\Providers\ModelCapabilities;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * `Laravel\Ai\Responses\Data\TextUsage` → our `AiUsage`, plus the cost the SDK
 * does not know (ADR-004 D2 row 8, D7 provenance).
 *
 * Cost is tokens × the per-model price in `resources/ai/models.json`:
 *
 * - a local model records its tokens with cost `0.0` — it is the owner's own
 *   hardware, and recording `null` would make a local run look unaccounted;
 * - an external model whose EUR price the registry does not carry records
 *   `null`, never an estimate, because a made-up number would flow straight
 *   into D7's budget and refuse or allow runs for invented reasons.
 */
final readonly class UsageMapper
{
    public function map(TextUsage $usage, ModelCapabilities $capabilities): AiUsage
    {
        return new AiUsage(
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadInputTokens: $usage->cacheReadInputTokens,
            cacheWriteInputTokens: $usage->cacheWriteInputTokens,
            reasoningTokens: $usage->reasoningTokens,
            costEur: $this->cost($usage, $capabilities),
        );
    }

    private function cost(TextUsage $usage, ModelCapabilities $capabilities): ?float
    {
        $input = $capabilities->inputPricePerMTok;
        $output = $capabilities->outputPricePerMTok;

        if ($input === null || $output === null) {
            return null;
        }

        // Cached reads and writes are billed at their own rates per vendor; until
        // the registry carries them, they are counted as ordinary input tokens
        // rather than silently dropped from the bill.
        $inputTokens = $usage->inputTokens;
        $outputTokens = $usage->outputTokens;

        return ($inputTokens * $input + $outputTokens * $output) / 1_000_000;
    }
}
