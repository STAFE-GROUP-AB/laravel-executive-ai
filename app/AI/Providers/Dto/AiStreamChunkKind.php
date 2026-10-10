<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * The SDK stream events we surface (`Laravel\Ai\Streaming\Events\TextDelta`,
 * `ReasoningDelta`, `ToolCall`, `StreamEnd`, `Error`); start/end markers for text
 * and reasoning blocks are folded into the deltas.
 */
enum AiStreamChunkKind: string
{
    case TextDelta = 'text_delta';
    case ReasoningDelta = 'reasoning_delta';
    case ToolCall = 'tool_call';
    case End = 'end';
    case Error = 'error';
}
