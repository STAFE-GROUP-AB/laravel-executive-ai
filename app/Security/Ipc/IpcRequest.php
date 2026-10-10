<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The per-request IPC state the middleware leaves on the request's attribute
 * bag: the request id (echoed or minted, §4.1), the verified session (set as
 * soon as step 3 of §4.4 passes, so later rejections are signed) and the
 * principal the controllers authorise with.
 */
final class IpcRequest
{
    public const string REQUEST_ID = 'ipc.request_id';

    public const string SESSION = 'ipc.session';

    public const string PRINCIPAL = 'ipc.principal';

    public const string NONCE = 'ipc.nonce';

    public static function requestId(Request $request): string
    {
        $existing = $request->attributes->get(self::REQUEST_ID);

        if (is_string($existing)) {
            return $existing;
        }

        $presented = $request->headers->get('X-Lxai-Request-Id');
        $requestId = is_string($presented) && preg_match(IpcSigner::ULID_PATTERN, $presented) === 1
            ? $presented
            : (string) Str::ulid();

        $request->attributes->set(self::REQUEST_ID, $requestId);

        return $requestId;
    }

    public static function session(Request $request): ?IpcSession
    {
        $session = $request->attributes->get(self::SESSION);

        return $session instanceof IpcSession ? $session : null;
    }

    public static function principal(Request $request): IpcPrincipal
    {
        $principal = $request->attributes->get(self::PRINCIPAL);

        if (! $principal instanceof IpcPrincipal) {
            throw new \LogicException('No IPC principal on this request: the route is missing IpcAuthenticate.');
        }

        return $principal;
    }

    public static function nonce(Request $request): ?string
    {
        $nonce = $request->attributes->get(self::NONCE);

        return is_string($nonce) ? $nonce : null;
    }
}
