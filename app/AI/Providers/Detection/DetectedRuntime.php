<?php

declare(strict_types=1);

namespace App\AI\Providers\Detection;

/**
 * What a loopback probe found (blueprint §12.1, §12.3.5; ADR-004 D3). A result
 * is information for the SELECT_AI screen, which lists what it found as
 * *options* in alphabetical order with nothing preselected — "finding exactly
 * one runtime does not select it".
 *
 * `tokenRequired` is the LM Studio `401` case: the runtime is running and needs
 * a bearer token, which must be reported as "running, token required" and never
 * as "not installed".
 */
final readonly class DetectedRuntime
{
    /**
     * @param  string  $providerId  catalogue id (`ollama`, `lmstudio`)
     * @param  list<DetectedModel>  $models
     */
    private function __construct(
        public string $providerId,
        public string $driver,
        public bool $running,
        public ?string $version = null,
        public array $models = [],
        public bool $tokenRequired = false,
        public ?string $baseUrl = null,
    ) {}

    /**
     * @param  list<DetectedModel>  $models
     */
    public static function running(string $providerId, string $driver, string $baseUrl, ?string $version = null, array $models = []): self
    {
        return new self(
            providerId: $providerId,
            driver: $driver,
            running: true,
            version: $version,
            models: $models,
            baseUrl: $baseUrl,
        );
    }

    public static function needsToken(string $providerId, string $driver, string $baseUrl): self
    {
        return new self(
            providerId: $providerId,
            driver: $driver,
            running: true,
            tokenRequired: true,
            baseUrl: $baseUrl,
        );
    }

    public static function absent(string $providerId, string $driver): self
    {
        return new self(providerId: $providerId, driver: $driver, running: false);
    }

    /** @return list<string> */
    public function modelIds(): array
    {
        return array_map(static fn (DetectedModel $model): string => $model->modelId, $this->models);
    }
}
