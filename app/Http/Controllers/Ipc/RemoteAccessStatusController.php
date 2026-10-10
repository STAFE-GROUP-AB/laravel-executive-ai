<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\IpcRequest;
use App\Security\Ipc\IpcResponder;
use App\Support\Install\TunnelProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /ipc/v1/remote-access (IPC contract §6.7): `TunnelProvider::inspect()`
 * as the ADR-006 D2 state. Gate: `viewRemoteAccess`.
 */
final class RemoteAccessStatusController extends Controller
{
    public function __invoke(Request $request, TunnelProvider $tunnel, IpcResponder $responder): Response
    {
        IpcRequest::principal($request)->authorize(OwnerAbilities::VIEW_REMOTE_ACCESS);

        return $responder->success($request, RemoteAccessData::status($tunnel->inspect()));
    }
}
