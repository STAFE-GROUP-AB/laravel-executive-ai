<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * One turn of app-owned conversation state (blueprint §4: conversation and memory
 * are app-owned; the SDK's `agent_conversations` store is not used, ADR-004 D1).
 *
 * `content` is data. When it originates outside the app (email bodies, web pages,
 * tool output, MCP responses) it is untrusted input to the model and must never be
 * interpreted as an instruction by our code (§7, §11).
 */
final readonly class AiMessage
{
    /**
     * @param  list<AiAttachment>  $attachments
     */
    public function __construct(
        public AiMessageRole $role,
        public string $content,
        public array $attachments = [],
        public ?string $toolCallId = null,
    ) {}
}
