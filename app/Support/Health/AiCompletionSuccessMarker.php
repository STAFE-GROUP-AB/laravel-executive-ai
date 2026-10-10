<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Contracts\Cache\Repository;

/**
 * Where the last succeeded completion is kept until `ai_runs` exists (Sprint 2
 * rebinds AiReadiness over that table). Written only by RecordAiCompletionSuccess;
 * read by ConfiguredProviderAiReadiness. Stored without expiry: a success stays
 * valid until the provider changes or a later completion fails. Losing the cache
 * fails safe — the install goes back to "AI setup required", never to a false
 * "AI ready".
 */
final class AiCompletionSuccessMarker
{
    public const string KEY = 'health:ai:last_success';

    public function __construct(private readonly Repository $cache) {}

    public function record(AiCompletionSuccess $success): void
    {
        $this->cache->forever(self::KEY, $success->toArray());
    }

    public function read(): ?AiCompletionSuccess
    {
        $value = $this->cache->get(self::KEY);

        if (! is_array($value)) {
            return null;
        }

        if (! isset($value['provider'], $value['model'], $value['at'])) {
            return null;
        }

        if (! is_string($value['provider']) || ! is_string($value['model']) || ! is_string($value['at'])) {
            return null;
        }

        return AiCompletionSuccess::fromArray(['provider' => $value['provider'], 'model' => $value['model'], 'at' => $value['at']]);
    }

    public function forget(): void
    {
        $this->cache->forget(self::KEY);
    }
}
