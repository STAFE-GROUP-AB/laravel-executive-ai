<?php

declare(strict_types=1);

namespace App\AI\Providers\Detection;

use App\AI\Providers\ModelCapability;
use App\AI\Routing\ProbedCapabilities;

/**
 * Detects a running Ollama on loopback (ADR-004 D3): `GET /api/version`, then
 * `GET /api/tags` for the installed models and `GET /api/ps` for the loaded
 * ones. `POST /api/show` supplies the capability overlay for one model.
 *
 * Verified against Ollama's API documentation: default port 11434; `/api/tags`
 * lists `name`, `model`, `size`, `digest` and `details` (`family`,
 * `parameter_size`, `quantization_level`); `/api/show` returns `details`,
 * `model_info`, the licence text and a `capabilities` array.
 */
final readonly class OllamaDetector implements LocalRuntimeDetector
{
    public const string PROVIDER_ID = 'ollama';

    public const string DRIVER = 'ollama';

    public const int PORT = 11434;

    public function __construct(private LoopbackProbe $probe) {}

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function detect(): DetectedRuntime
    {
        $version = $this->probe->get(self::PORT, '/api/version');

        if ($version === null || ! $version->successful()) {
            return DetectedRuntime::absent(self::PROVIDER_ID, self::DRIVER);
        }

        $loaded = $this->loadedModelNames();

        return DetectedRuntime::running(
            providerId: self::PROVIDER_ID,
            driver: self::DRIVER,
            baseUrl: $this->probe->baseUrl(self::PORT),
            version: is_string($version->json('version')) ? $version->json('version') : null,
            models: $this->installedModels($loaded),
        );
    }

    /**
     * The capability overlay for one installed model, from `POST /api/show`.
     * Ollama's `capabilities` array is the runtime's own report: it may fill a
     * field the registry left unknown and may never override a registry `false`
     * (ADR-004 D3), which `ModelCapabilityRegistry` enforces.
     */
    public function probeModel(string $modelId): ?ProbedCapabilities
    {
        $response = $this->probe->post(self::PORT, '/api/show', ['model' => $modelId]);

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $reported = $response->json('capabilities');
        $capabilities = [];

        if (is_array($reported)) {
            foreach ($reported as $capability) {
                $mapped = match ($capability) {
                    'completion' => ModelCapability::Text,
                    'tools' => ModelCapability::Tools,
                    'vision' => ModelCapability::Vision,
                    'embedding' => ModelCapability::Embeddings,
                    default => null,
                };

                if ($mapped !== null) {
                    $capabilities[] = $mapped;
                }
            }
        }

        $details = $response->json('details');
        $quantization = is_array($details) && is_string($details['quantization_level'] ?? null)
            ? $details['quantization_level']
            : null;

        return new ProbedCapabilities(
            driver: self::DRIVER,
            modelId: $modelId,
            capabilities: $capabilities,
            contextWindow: $this->contextLengthFrom($response->json('model_info')),
            quantization: $quantization,
        );
    }

    /**
     * `model_info` is keyed by architecture (`llama.context_length`,
     * `qwen3.context_length`, ...), so the key is found by suffix rather than
     * guessed from the model name.
     */
    private function contextLengthFrom(mixed $modelInfo): ?int
    {
        if (! is_array($modelInfo)) {
            return null;
        }

        foreach ($modelInfo as $key => $value) {
            if (is_string($key) && str_ends_with($key, '.context_length') && is_int($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $loaded
     * @return list<DetectedModel>
     */
    private function installedModels(array $loaded): array
    {
        $response = $this->probe->get(self::PORT, '/api/tags');

        if ($response === null || ! $response->successful()) {
            return [];
        }

        $models = $response->json('models');

        if (! is_array($models)) {
            return [];
        }

        $detected = [];

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $name = $model['model'] ?? $model['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $details = is_array($model['details'] ?? null) ? $model['details'] : [];

            $detected[] = new DetectedModel(
                modelId: $name,
                loaded: in_array($name, $loaded, true),
                quantization: is_string($details['quantization_level'] ?? null) ? $details['quantization_level'] : null,
                parameterSize: is_string($details['parameter_size'] ?? null) ? $details['parameter_size'] : null,
                digest: is_string($model['digest'] ?? null) ? $model['digest'] : null,
            );
        }

        return $detected;
    }

    /** @return list<string> */
    private function loadedModelNames(): array
    {
        $response = $this->probe->get(self::PORT, '/api/ps');

        if ($response === null || ! $response->successful()) {
            return [];
        }

        $models = $response->json('models');

        if (! is_array($models)) {
            return [];
        }

        $names = [];

        foreach ($models as $model) {
            $name = is_array($model) ? ($model['model'] ?? $model['name'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
