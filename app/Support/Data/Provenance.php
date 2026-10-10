<?php

declare(strict_types=1);

namespace App\Support\Data;

use Carbon\CarbonImmutable;

/**
 * Builds the §3.1 `provenance` object stored on every business row:
 * {origin, actor, recorded_at, trace_id}. The actor grammar is the CRM
 * fixture schema's: `user:<ulid>`, `job:<JobName>` or `ai:<provider>/<model>`.
 * It never carries credentials or prompt text.
 */
final class Provenance
{
    /** @return array{origin: string, actor: string, recorded_at: string, trace_id: ?string} */
    public static function job(string $jobName, ProvenanceOrigin $origin = ProvenanceOrigin::Sync, ?string $traceId = null): array
    {
        return self::make($origin, "job:{$jobName}", $traceId);
    }

    /** @return array{origin: string, actor: string, recorded_at: string, trace_id: ?string} */
    public static function user(string $userId, ProvenanceOrigin $origin = ProvenanceOrigin::Manual): array
    {
        return self::make($origin, "user:{$userId}", null);
    }

    /** @return array{origin: string, actor: string, recorded_at: string, trace_id: ?string} */
    public static function ai(string $provider, string $model, ?string $traceId): array
    {
        return self::make(ProvenanceOrigin::AiExtraction, "ai:{$provider}/{$model}", $traceId);
    }

    /** @return array{origin: string, actor: string, recorded_at: string, trace_id: ?string} */
    private static function make(ProvenanceOrigin $origin, string $actor, ?string $traceId): array
    {
        return [
            'origin' => $origin->value,
            'actor' => $actor,
            'recorded_at' => CarbonImmutable::now('UTC')->toRfc3339String(),
            'trace_id' => $traceId,
        ];
    }
}
