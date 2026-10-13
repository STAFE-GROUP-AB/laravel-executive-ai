<?php

declare(strict_types=1);

namespace App\AI\Providers\Detection;

use App\AI\Providers\ModelCapability;
use App\AI\Routing\ProbedCapabilities;
use Illuminate\Http\Client\Response;

/**
 * Detects a running LM Studio on loopback (ADR-004 D3): `GET /v1/models` for
 * presence, then `GET /api/v0/models` — LM Studio 0.3.6 and newer — for the
 * richer record with `type` (`llm`/`vlm`/`embeddings`), `quantization`, `state`
 * and `max_context_length`.
 *
 * Two behaviours are deliberate and tested. A `401` from either endpoint means
 * **running, token required**, never "not installed", because LM Studio's REST
 * API can be behind a bearer token and telling the owner the app is missing
 * would send them to reinstall something they already have. And the v0 endpoint
 * is optional: an older build answers only on `/v1/models`, which is enough to
 * offer the runtime as an option.
 */
final readonly class LmStudioDetector implements LocalRuntimeDetector
{
    public const string PROVIDER_ID = 'lmstudio';

    public const string DRIVER = 'openai-compatible';

    public const int PORT = 1234;

    public function __construct(private LoopbackProbe $probe) {}

    public function providerId(): string
    {
        return self::PROVIDER_ID;
    }

    public function detect(): DetectedRuntime
    {
        $openAi = $this->probe->get(self::PORT, '/v1/models');

        if ($openAi === null) {
            return DetectedRuntime::absent(self::PROVIDER_ID, self::DRIVER);
        }

        if ($openAi->status() === 401) {
            return DetectedRuntime::needsToken(self::PROVIDER_ID, self::DRIVER, $this->baseUrl());
        }

        if (! $openAi->successful()) {
            return DetectedRuntime::absent(self::PROVIDER_ID, self::DRIVER);
        }

        $rest = $this->probe->get(self::PORT, '/api/v0/models');

        if ($rest !== null && $rest->status() === 401) {
            return DetectedRuntime::needsToken(self::PROVIDER_ID, self::DRIVER, $this->baseUrl());
        }

        return DetectedRuntime::running(
            providerId: self::PROVIDER_ID,
            driver: self::DRIVER,
            baseUrl: $this->baseUrl(),
            models: $rest !== null && $rest->successful()
                ? $this->modelsFromRestApi($rest)
                : $this->modelsFromOpenAiApi($openAi),
        );
    }

    /**
     * The capability overlay for one model, from `GET /api/v0/models`. A `vlm`
     * is a vision model, an `embeddings` model does embeddings and nothing
     * else; `max_context_length` fills a context window the registry does not
     * know. As with Ollama, a probe never overrides a registry `false`.
     */
    public function probeModel(string $modelId): ?ProbedCapabilities
    {
        $response = $this->probe->get(self::PORT, '/api/v0/models');

        if ($response === null || ! $response->successful()) {
            return null;
        }

        foreach ($this->records($response) as $record) {
            if (($record['id'] ?? null) !== $modelId) {
                continue;
            }

            $type = is_string($record['type'] ?? null) ? $record['type'] : null;
            $contextWindow = is_int($record['max_context_length'] ?? null) ? $record['max_context_length'] : null;

            return new ProbedCapabilities(
                driver: self::DRIVER,
                modelId: $modelId,
                capabilities: match ($type) {
                    'vlm' => [ModelCapability::Text, ModelCapability::Vision],
                    'embeddings' => [ModelCapability::Embeddings],
                    'llm' => [ModelCapability::Text],
                    default => [],
                },
                contextWindow: $contextWindow,
                quantization: is_string($record['quantization'] ?? null) ? $record['quantization'] : null,
            );
        }

        return null;
    }

    /**
     * The base URL the `openai-compatible` driver needs: LM Studio serves the
     * OpenAI-compatible surface under `/v1`.
     */
    public function baseUrl(): string
    {
        return $this->probe->baseUrl(self::PORT).'/v1';
    }

    /** @return list<DetectedModel> */
    private function modelsFromRestApi(Response $response): array
    {
        $models = [];

        foreach ($this->records($response) as $record) {
            $id = $record['id'] ?? null;

            if (! is_string($id) || $id === '') {
                continue;
            }

            $models[] = new DetectedModel(
                modelId: $id,
                loaded: ($record['state'] ?? null) === 'loaded',
                contextWindow: is_int($record['max_context_length'] ?? null) ? $record['max_context_length'] : null,
                quantization: is_string($record['quantization'] ?? null) ? $record['quantization'] : null,
            );
        }

        return $models;
    }

    /** @return list<DetectedModel> */
    private function modelsFromOpenAiApi(Response $response): array
    {
        $models = [];

        foreach ($this->records($response) as $record) {
            $id = $record['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $models[] = new DetectedModel(modelId: $id);
            }
        }

        return $models;
    }

    /**
     * Both endpoints answer `{"data": [...]}`; the v1 REST API of LM Studio
     * 0.4.0 keeps the same envelope, so one reader serves all three.
     *
     * @return list<array<string, mixed>>
     */
    private function records(Response $response): array
    {
        $data = $response->json('data');

        if (! is_array($data)) {
            return [];
        }

        $records = [];

        foreach ($data as $record) {
            if (is_array($record)) {
                /** @var array<string, mixed> $record */
                $records[] = $record;
            }
        }

        return $records;
    }
}
