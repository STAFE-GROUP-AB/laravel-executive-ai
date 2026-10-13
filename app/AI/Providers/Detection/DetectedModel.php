<?php

declare(strict_types=1);

namespace App\AI\Providers\Detection;

/**
 * One model a detected local runtime has installed. `loaded` is the runtime's
 * own word for "in memory right now" (Ollama `/api/ps`, LM Studio's
 * `state: loaded`); it is shown as information and never used to preselect.
 */
final readonly class DetectedModel
{
    public function __construct(
        public string $modelId,
        public bool $loaded = false,
        public ?int $contextWindow = null,
        public ?string $quantization = null,
        public ?string $parameterSize = null,
        public ?string $digest = null,
    ) {}
}
