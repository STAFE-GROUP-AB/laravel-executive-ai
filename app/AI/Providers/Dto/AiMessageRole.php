<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * Mirrors `Laravel\Ai\Messages\MessageRole` (assistant, user, tool_result) plus the
 * system role, which the SDK carries as agent instructions rather than a message.
 * Mirrored, not reused, because SDK types never leave `App\AI\Providers\LaravelAi`
 * (ADR-004 D2).
 */
enum AiMessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case ToolResult = 'tool_result';
}
