<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

/**
 * A tool call the model proposed. Mirrors `Laravel\Ai\Responses\Data\ToolCall`
 * (id, name, arguments) without the provider reasoning fields.
 *
 * This is a proposal, not a permission: `arguments` is model output whose shape is
 * declared by the tool's schema and validated by the PolicyGate before any executor
 * sees it (§4, §11). Nothing in `App\AI` may act on it directly.
 */
final readonly class AiToolCall
{
    /**
     * @param  array<string, mixed>  $arguments  decoded JSON object as emitted by the model; validated by the policy layer, not here
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
