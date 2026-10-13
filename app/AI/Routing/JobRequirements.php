<?php

declare(strict_types=1);

namespace App\AI\Routing;

use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\ModelCapability;
use InvalidArgumentException;

/**
 * What a job needs from a model before it may run (ADR-004 D3
 * `ModelRouter::resolve(JobRequirements, PrivacyMode)`). Capabilities are
 * requirements, not preferences: a model that lacks one is excluded, and an
 * empty result is a refusal.
 */
final readonly class JobRequirements
{
    /**
     * @param  list<ModelCapability>  $capabilities  every capability the job needs
     * @param  int  $contextTokens  the prompt's measured size; a model whose window is smaller is excluded, never truncated into (D3)
     */
    public function __construct(
        public string $jobClass,
        public array $capabilities = [ModelCapability::Text],
        public int $contextTokens = 0,
        public ?int $maxOutputTokens = null,
    ) {
        if (trim($this->jobClass) === '') {
            throw new InvalidArgumentException('Job requirements must name the job.');
        }

        if ($this->contextTokens < 0) {
            throw new InvalidArgumentException('Context tokens cannot be negative.');
        }
    }

    /**
     * The requirements a concrete request implies: its own capability set plus
     * the context it will occupy. Used so the adapter and the router agree on
     * what a request needs without the caller restating it.
     */
    public static function forRequest(AiRequest $request, string $jobClass, int $contextTokens = 0): self
    {
        return new self(
            jobClass: $jobClass,
            capabilities: $request->requiredCapabilities(),
            contextTokens: $contextTokens,
            maxOutputTokens: $request->maxOutputTokens,
        );
    }
}
