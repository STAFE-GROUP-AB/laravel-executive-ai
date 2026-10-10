<?php

declare(strict_types=1);

namespace App\AI\Providers\Exceptions;

use App\AI\Providers\Dto\AiError;
use RuntimeException;

/**
 * Base class for every failure an AiProviderAdapter raises. The classified `error`
 * is what `ai_runs.outcome` and the UI consume; vendor exceptions never escape the
 * adapter (ADR-004 D2 `ErrorMapper`).
 */
abstract class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly AiError $error,
    ) {
        parent::__construct($error->message);
    }
}
