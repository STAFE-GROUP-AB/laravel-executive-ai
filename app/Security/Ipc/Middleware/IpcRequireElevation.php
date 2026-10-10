<?php

declare(strict_types=1);

namespace App\Security\Ipc\Middleware;

use App\Security\Ipc\IpcElevationVerifier;
use App\Security\Ipc\IpcException;
use App\Security\Ipc\IpcKeyUnavailableException;
use App\Security\Ipc\IpcRequest;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * §4.4 step 9 / §7.3: the elevated operations (v1: exactly
 * `remote-access-enable`) require the one-shot `X-Lxai-Elevation` the
 * Supervisor minted after device-owner authentication. Elevation is
 * additive (§11.3): it runs before the gate, and the gate still runs in the
 * controller.
 */
final class IpcRequireElevation
{
    public function __construct(private readonly IpcElevationVerifier $elevations) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $session = IpcRequest::session($request);
        $nonce = IpcRequest::nonce($request);

        if ($session === null || $nonce === null) {
            throw new LogicException('IpcRequireElevation requires IpcAuthenticate before it.');
        }

        try {
            $this->elevations->verify($request->headers->get('X-Lxai-Elevation'), $session, $operation, $nonce);
        } catch (IpcKeyUnavailableException) {
            throw IpcException::secretsUnavailable();
        }

        return $next($request);
    }
}
