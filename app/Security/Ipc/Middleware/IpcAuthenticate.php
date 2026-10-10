<?php

declare(strict_types=1);

namespace App\Security\Ipc\Middleware;

use App\Security\Ipc\IpcAuditor;
use App\Security\Ipc\IpcClient;
use App\Security\Ipc\IpcException;
use App\Security\Ipc\IpcKeyUnavailableException;
use App\Security\Ipc\IpcNonceCache;
use App\Security\Ipc\IpcPrincipal;
use App\Security\Ipc\IpcRequest;
use App\Security\Ipc\IpcResponder;
use App\Security\Ipc\IpcSession;
use App\Security\Ipc\IpcSigner;
use App\Security\Ipc\IpcTokenVerifier;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IPC contract §4.4 steps 1–7 in one middleware, fail closed, cheapest first:
 *
 *  1. origin and host (§5.2)                    → 403 origin_rejected   (unsigned)
 *  2. X-Lxai-Ipc-Version supported (§5.1)       → 426 version_unsupported (unsigned)
 *  3. token parses, MAC valid, not expired (§3.3) → 401 session_invalid  (unsigned)
 *  4. client allowed on this route (§2)         → 403 forbidden_scope
 *  5. X-Lxai-Timestamp within ±30 s             → 401 timestamp_skew
 *  6. X-Lxai-Nonce well-formed and unseen (§4.3) → 401 nonce_replayed
 *  7. X-Lxai-Signature matches (§4.2)           → 401 signature_invalid
 *
 * then the §4.1 header allowlist and the §1.1 size and media-type rules
 * (400 / 413 / 415). From step 4 on, every response — including the ones
 * later middleware, the controller or the exception handler produce — is
 * signed with the session's key (§4.5). The route parameter is the scope:
 * `IpcAuthenticate:menubar` or `IpcAuthenticate:menubar,supervisor`.
 */
final class IpcAuthenticate
{
    /** ADR-006 D6.5: the loopback peer is a constant, not configuration. */
    public const string LOOPBACK_PEER = '127.0.0.1';

    private const array FORBIDDEN_HEADERS = ['origin', 'referer', 'cookie', 'forwarded'];

    private const array FORBIDDEN_PREFIXES = ['x-forwarded-', 'tailscale-user-', 'sec-fetch-'];

    private const array ALLOWED_LXAI_HEADERS = [
        'x-lxai-ipc-version', 'x-lxai-timestamp', 'x-lxai-nonce', 'x-lxai-signature', 'x-lxai-request-id', 'x-lxai-elevation',
    ];

    public function __construct(
        private readonly IpcTokenVerifier $tokens,
        private readonly IpcNonceCache $nonces,
        private readonly IpcResponder $responder,
        private readonly IpcAuditor $auditor,
    ) {}

    public function handle(Request $request, Closure $next, string ...$scope): Response
    {
        $startedAt = hrtime(true);
        IpcRequest::requestId($request);

        try {
            $this->authenticate($request, $this->allowedClients($scope));
            $response = $next($request);
        } catch (IpcException $exception) {
            $response = $this->responder->error($request, $exception)->withException($exception);
        }

        $response = $this->responder->finalise($request, $response);

        if (property_exists($response, 'exception') && $response->exception instanceof IpcException) {
            $this->auditor->rejected($request, $response->exception);
        }

        $this->auditor->requestLine($request, $response, $startedAt);

        return $response;
    }

    /** @param list<IpcClient> $allowed */
    private function authenticate(Request $request, array $allowed): void
    {
        $this->checkOrigin($request);
        $this->checkVersion($request);

        try {
            $session = $this->tokens->verify($request->headers->get('Authorization'));
        } catch (IpcKeyUnavailableException) {
            throw IpcException::secretsUnavailable();
        }

        $request->attributes->set(IpcRequest::SESSION, $session);

        if (! in_array($session->client, $allowed, true)) {
            throw IpcException::forbiddenScope();
        }

        $timestamp = $this->checkTimestamp($request);
        $nonce = $this->checkNonce($request, $session);
        $this->checkSignature($request, $session, $timestamp, $nonce);

        if (! $this->nonces->remember($session->sessionId, $nonce)) {
            throw IpcException::nonceReplayed();
        }

        $request->attributes->set(IpcRequest::NONCE, $nonce);

        $this->checkHeadersAndBody($request);

        $request->attributes->set(IpcRequest::PRINCIPAL, new IpcPrincipal($session));
    }

    /** §5.2: all five conditions, any failure is origin_rejected. */
    private function checkOrigin(Request $request): void
    {
        if ((string) $request->server('REMOTE_ADDR') !== self::LOOPBACK_PEER) {
            throw IpcException::originRejected();
        }

        if ($request->headers->get('Host') !== IpcResponder::origin()) {
            throw IpcException::originRejected();
        }

        foreach ($request->headers->keys() as $name) {
            $name = strtolower((string) $name);

            if (in_array($name, self::FORBIDDEN_HEADERS, true)) {
                throw IpcException::originRejected();
            }

            foreach (self::FORBIDDEN_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    throw IpcException::originRejected();
                }
            }
        }
    }

    /** §5.1 */
    private function checkVersion(Request $request): void
    {
        /** @var list<int> $supported */
        $supported = array_values(array_map(intval(...), (array) config('ipc.supported_versions', [1])));
        $presented = $request->headers->get('X-Lxai-Ipc-Version');

        if (! is_string($presented) || preg_match('/^[1-9][0-9]*$/', $presented) !== 1 || ! in_array((int) $presented, $supported, true)) {
            throw IpcException::versionUnsupported($supported);
        }
    }

    /** §4.1: Unix seconds on the client clock, |now − ts| ≤ skew. */
    private function checkTimestamp(Request $request): string
    {
        $presented = $request->headers->get('X-Lxai-Timestamp');

        if (! is_string($presented) || preg_match('/^[0-9]{1,12}$/', $presented) !== 1) {
            throw IpcException::timestampSkew();
        }

        $skew = abs(CarbonImmutable::now('UTC')->getTimestamp() - (int) $presented);

        if ($skew > (int) config('ipc.skew_seconds')) {
            throw IpcException::timestampSkew();
        }

        return $presented;
    }

    /** §4.3: well-formed and not seen within the window for this session. */
    private function checkNonce(Request $request, IpcSession $session): string
    {
        $presented = $request->headers->get('X-Lxai-Nonce');

        if (! is_string($presented) || preg_match(IpcSigner::NONCE_PATTERN, $presented) !== 1) {
            throw IpcException::nonceReplayed();
        }

        if ($this->nonces->wasSeen($session->sessionId, $presented)) {
            throw IpcException::nonceReplayed();
        }

        return $presented;
    }

    /** §4.2: rebuilt from the request as received; constant-time compare. */
    private function checkSignature(Request $request, IpcSession $session, string $timestamp, string $nonce): void
    {
        $presented = $request->headers->get('X-Lxai-Signature');

        if (! is_string($presented) || preg_match(IpcSigner::SIGNATURE_PATTERN, $presented) !== 1) {
            throw IpcException::signatureInvalid();
        }

        $expected = IpcSigner::signRequest(
            $session->signingKey(),
            strtoupper($request->getRealMethod()),
            $request->getRequestUri(),
            $timestamp,
            $nonce,
            $request->getContent(),
        );

        if (! IpcSigner::constantTimeEquals($expected, $presented)) {
            throw IpcException::signatureInvalid();
        }
    }

    /** §4.1 header allowlist, §1.1 body size, §5.2.5 media type. */
    private function checkHeadersAndBody(Request $request): void
    {
        foreach ($request->headers->keys() as $name) {
            $name = strtolower((string) $name);

            if (str_starts_with($name, 'x-lxai-') && ! in_array($name, self::ALLOWED_LXAI_HEADERS, true)) {
                throw IpcException::badRequest('unknown_header');
            }

            if ($name === 'x-http-method-override') {
                throw IpcException::badRequest('method_override');
            }
        }

        $content = $request->getContent();

        if (strlen($content) > (int) config('ipc.max_request_body_bytes')) {
            throw IpcException::payloadTooLarge();
        }

        if ($content === '') {
            return;
        }

        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''), 2)[0]));

        if ($mediaType !== 'application/json') {
            throw IpcException::unsupportedMediaType();
        }
    }

    /**
     * @param  list<string>  $scope
     * @return list<IpcClient>
     */
    private function allowedClients(array $scope): array
    {
        $clients = [];

        foreach ($scope === [] ? ['menubar'] : $scope as $entry) {
            foreach (explode(',', $entry) as $name) {
                $clients[] = IpcClient::from(trim($name));
            }
        }

        return $clients;
    }
}
