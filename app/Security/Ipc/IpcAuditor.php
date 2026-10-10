<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use App\Security\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * §10: the request log line `{ts, request_id, session_id, client, method,
 * path, status, duration_ms}` and the `ipc.rejected` audit event with the
 * reason code, the `(timestamp, nonce)` pair and never the presented token,
 * signature, elevation or body.
 */
final class IpcAuditor
{
    public const string ACTOR = 'job:IpcServer';

    public function __construct(private readonly AuditLog $audit) {}

    public function rejected(Request $request, IpcException $exception): void
    {
        $session = IpcRequest::session($request);

        $context = [
            'reason' => $exception->errorCode->value,
            'client' => $session?->client->value,
            'session_id' => $session?->sessionId,
            'request_id' => IpcRequest::requestId($request),
            'method' => $request->getRealMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'ts' => self::digits($request->headers->get('X-Lxai-Timestamp')),
            'nonce' => self::nonce($request->headers->get('X-Lxai-Nonce')),
        ];

        try {
            $this->audit->record('ipc.rejected', self::ACTOR, AuditLog::OUTCOME_REFUSED, null, null, array_filter($context, static fn (mixed $value): bool => $value !== null));
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    public function requestLine(Request $request, Response $response, int $startedAtHrtime): void
    {
        $session = IpcRequest::session($request);

        Log::info('ipc.request', [
            'request_id' => IpcRequest::requestId($request),
            'session_id' => $session?->sessionId,
            'client' => $session?->client->value,
            'method' => $request->getRealMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) intdiv(hrtime(true) - $startedAtHrtime, 1_000_000),
        ]);
    }

    private static function digits(?string $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9]{1,12}$/', $value) === 1 ? $value : null;
    }

    private static function nonce(?string $value): ?string
    {
        return is_string($value) && preg_match(IpcSigner::NONCE_PATTERN, $value) === 1 ? $value : null;
    }
}
