<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * One element yielded by `AiProviderAdapter::stream()` (blueprint §3). Exactly the
 * fields for its `kind` are non-null; the terminal `End` chunk carries usage and the
 * finish reason (ADR-004 D2 row 2) and an `Error` chunk is always the last one.
 */
final readonly class AiStreamChunk
{
    public function __construct(
        public AiStreamChunkKind $kind,
        public ?string $delta = null,
        public ?AiToolCall $toolCall = null,
        public ?AiUsage $usage = null,
        public ?AiFinishReason $finishReason = null,
        public ?AiError $error = null,
    ) {}

    public static function text(string $delta): self
    {
        return new self(kind: AiStreamChunkKind::TextDelta, delta: $delta);
    }

    public static function reasoning(string $delta): self
    {
        return new self(kind: AiStreamChunkKind::ReasoningDelta, delta: $delta);
    }

    public static function toolCall(AiToolCall $toolCall): self
    {
        return new self(kind: AiStreamChunkKind::ToolCall, toolCall: $toolCall);
    }

    public static function end(AiUsage $usage, AiFinishReason $finishReason): self
    {
        return new self(kind: AiStreamChunkKind::End, usage: $usage, finishReason: $finishReason);
    }

    public static function error(AiError $error): self
    {
        return new self(kind: AiStreamChunkKind::Error, error: $error);
    }
}
