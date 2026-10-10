<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Builds the §8 envelopes and applies §4.5 to every response that leaves an
 * IPC route: the version, request-id, no-store and origin headers, and the
 * response signature whenever the request established a session. Envelope
 * bodies are emitted in RFC 8785 canonical form (the same bytes the fixture
 * signatures cover), so a deterministic exchange reproduces the recorded
 * signature byte for byte.
 */
final class IpcResponder
{
    public const string VERSION_HEADER = 'X-Lxai-Ipc-Version';

    public const string REQUEST_ID_HEADER = 'X-Lxai-Request-Id';

    public const string SIGNATURE_HEADER = 'X-Lxai-Signature';

    public const string ORIGIN_HEADER = 'X-Lxai-Origin';

    public const string CONTENT_TYPE = 'application/json; charset=utf-8';

    public const int WIRE_MAJOR = 1;

    public static function serverTime(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d\TH:i:sP');
    }

    /** `127.0.0.1:<port>` (§4.5, ADR-006 D3). */
    public static function origin(): string
    {
        return '127.0.0.1:'.(int) config('ipc.port');
    }

    public static function originUrl(): string
    {
        return 'http://'.self::origin();
    }

    /** @param array<string, mixed> $data */
    public function success(Request $request, array $data, int $status = 200): Response
    {
        return $this->envelope([
            'ok' => true,
            'request_id' => IpcRequest::requestId($request),
            'server_time' => self::serverTime(),
            'data' => $data,
        ], $status);
    }

    public function error(Request $request, IpcException $exception): Response
    {
        $response = $this->envelope([
            'ok' => false,
            'request_id' => IpcRequest::requestId($request),
            'server_time' => self::serverTime(),
            'error' => [
                'code' => $exception->errorCode->value,
                'message' => $exception->errorCode->message(),
                'hint' => $exception->errorCode->hint(),
                'retry_after_seconds' => $exception->retryAfterSeconds,
                'supported_versions' => $exception->supportedVersions,
                'details' => $exception->details,
            ],
        ], $exception->status());

        if ($exception->retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
        }

        return $response;
    }

    /** §6.2: an empty body, still signed over the empty-body hash. */
    public function notModified(Request $request, string $etag): Response
    {
        return (new Response('', 304))->header('ETag', $etag);
    }

    /**
     * Applied by IpcAuthenticate on the way out. A response rendered by the
     * exception handler for anything other than an IpcException is rewritten
     * into the error envelope so the client never sees a framework body.
     */
    public function finalise(Request $request, SymfonyResponse $response): SymfonyResponse
    {
        $exception = $this->exceptionOf($response);

        if ($exception !== null && ! $exception instanceof IpcException) {
            $response = $this->error($request, $this->translate($exception));
            $response->withException($exception);
        }

        $response->headers->set(self::VERSION_HEADER, (string) self::WIRE_MAJOR);
        $response->headers->set(self::REQUEST_ID_HEADER, IpcRequest::requestId($request));
        $response->headers->set(self::ORIGIN_HEADER, self::origin());
        $response->headers->set('Cache-Control', 'no-store');

        $session = IpcRequest::session($request);
        $nonce = $request->headers->get('X-Lxai-Nonce');

        if ($session !== null && is_string($nonce)) {
            $body = $response->getContent();
            $response->headers->set(
                self::SIGNATURE_HEADER,
                IpcSigner::signResponse($session->signingKey(), $response->getStatusCode(), $nonce, $body === false ? '' : $body),
            );
        }

        return $response;
    }

    /** @param array<string, mixed> $payload */
    private function envelope(array $payload, int $status): Response
    {
        return new Response(Jcs::canonicalize($payload), $status, ['Content-Type' => self::CONTENT_TYPE]);
    }

    private function exceptionOf(SymfonyResponse $response): ?Throwable
    {
        if (! property_exists($response, 'exception')) {
            return null;
        }

        $exception = $response->exception;

        return $exception instanceof Throwable ? $exception : null;
    }

    private function translate(Throwable $exception): IpcException
    {
        if ($exception instanceof ValidationException) {
            $details = [];

            foreach ($exception->errors() as $field => $messages) {
                $details[(string) $field] = (string) ($messages[0] ?? 'invalid');
            }

            return IpcException::validationFailed($details);
        }

        if ($exception instanceof AuthorizationException) {
            return IpcException::policyDenied();
        }

        if ($exception instanceof NotFoundHttpException || $exception instanceof ModelNotFoundException || $exception instanceof MethodNotAllowedHttpException) {
            return IpcException::notFound();
        }

        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                400 => IpcException::badRequest(),
                403 => IpcException::policyDenied(),
                404, 405 => IpcException::notFound(),
                413 => IpcException::payloadTooLarge(),
                415 => IpcException::unsupportedMediaType(),
                422 => IpcException::validationFailed([]),
                429 => IpcException::rateLimited(60),
                503 => IpcException::secretsUnavailable(),
                default => IpcException::internal(),
            };
        }

        return IpcException::internal();
    }
}
