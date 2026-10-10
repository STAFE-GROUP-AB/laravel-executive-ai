<?php

declare(strict_types=1);

namespace App\Security\Queue;

use App\Security\Secrets\Redactor;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Date;

/**
 * `failed_jobs.exception` passes through the Redactor before it is persisted
 * (ADR-003 D7 row 3, threat model T-11). The payload is stored as is: jobs
 * carry identifiers, never secret values (T-12), and rewriting a serialized
 * payload would make retry impossible.
 */
final class RedactingFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function __construct(
        ConnectionResolverInterface $resolver,
        ?string $database,
        string $table,
        private readonly Redactor $redactor,
    ) {
        parent::__construct($resolver, $database, $table);
    }

    /**
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Throwable  $exception
     */
    public function log($connection, $queue, $payload, $exception): ?string
    {
        $uuid = json_decode($payload, true)['uuid'] ?? null;
        $rendered = (string) mb_convert_encoding((string) $exception, 'UTF-8');

        $this->getTable()->insert([
            'uuid' => $uuid,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => $this->redactor->redactString($rendered),
            'failed_at' => Date::now(),
        ]);

        return $uuid;
    }
}
