<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\ModelCapability;

/**
 * The requested model cannot do what the request needs (ADR-004 D3, fail closed).
 * Carries the missing capabilities so the UI can say "this model cannot do X".
 */
final class CapabilityUnavailableException extends AiProviderException
{
    /**
     * @param  list<ModelCapability>  $missing
     */
    public function __construct(
        public readonly string $modelId,
        public readonly array $missing,
    ) {
        $names = implode(', ', array_map(
            fn (ModelCapability $capability): string => $capability->value,
            $missing,
        ));

        parent::__construct(new AiError(
            kind: AiErrorKind::CapabilityMissing,
            message: "Model {$modelId} does not support: {$names}.",
            retryable: false,
        ));
    }
}
