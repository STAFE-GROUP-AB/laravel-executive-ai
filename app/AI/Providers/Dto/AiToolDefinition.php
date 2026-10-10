<?php

declare(strict_types=1);

namespace App\AI\Providers\Dto;

use Illuminate\JsonSchema\Types\Type;

/**
 * A tool the model may *propose* calling. Definitions carry no executor: execution
 * happens only after the server-side PolicyGate approves the proposal (§4, §11;
 * ADR-004 D2 row 3). `parameters` uses the framework's JSON schema types, the same
 * shape `Laravel\Ai\Contracts\Tool::schema()` returns, so the SDK bridge is a
 * pass-through.
 */
final readonly class AiToolDefinition
{
    /**
     * @param  array<string, Type>  $parameters
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
