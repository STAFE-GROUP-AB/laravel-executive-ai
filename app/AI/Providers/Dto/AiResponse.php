<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * The completed result of `generate()` or the return value of an exhausted
 * `stream()` generator (blueprint §3). Wraps what `Laravel\Ai\Responses\TextResponse`
 * exposes (`text`, `usage`, `toolCalls`, `meta->provider/model`, `reasoning`) in our
 * own types; `structuredOutput` is populated only when the adapter validated the
 * model's JSON against the request schema (ADR-004 D2 row 4).
 *
 * `toolCalls` are proposals awaiting the PolicyGate; a response never reports a tool
 * as executed.
 */
final readonly class AiResponse
{
    /**
     * @param  list<AiToolCall>  $toolCalls
     * @param  array<string, mixed>|null  $structuredOutput  schema-validated JSON object, null when no schema was requested
     */
    public function __construct(
        public string $text,
        public AiFinishReason $finishReason,
        public AiUsage $usage,
        public string $provider,
        public string $model,
        public string $traceId,
        public array $toolCalls = [],
        public ?array $structuredOutput = null,
        public ?string $reasoning = null,
    ) {}
}
