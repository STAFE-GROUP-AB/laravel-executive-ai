<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * Mirrors `Laravel\Ai\Responses\Data\FinishReason` plus `Cancelled`, which the SDK
 * has no notion of (ADR-004 D2 row 6).
 */
enum AiFinishReason: string
{
    case Stop = 'stop';
    case ToolCalls = 'tool_calls';
    case Length = 'length';
    case ContentFilter = 'content_filter';
    case Error = 'error';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
