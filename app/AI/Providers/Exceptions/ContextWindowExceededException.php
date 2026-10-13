<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;

/**
 * The prompt does not fit the model's context window. ADR-004 D3 lists silent
 * truncation among the forbidden downgrades: "a context overflow is a refusal
 * with the token count shown", so both numbers travel on the exception and
 * nothing is dropped from the prompt to make it fit (threat model T-67).
 */
final class ContextWindowExceededException extends AiProviderException
{
    public function __construct(
        public readonly string $modelId,
        public readonly int $contextTokens,
        public readonly int $contextWindow,
    ) {
        parent::__construct(new AiError(
            kind: AiErrorKind::CapabilityMissing,
            message: "This request needs {$contextTokens} tokens of context but {$modelId} has room for {$contextWindow}.",
            retryable: false,
        ));
    }
}
