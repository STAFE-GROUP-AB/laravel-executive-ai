<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\IpcBody;
use App\Security\Ipc\IpcException;
use App\Security\Ipc\IpcRequest;
use App\Security\Ipc\IpcResponder;
use App\Support\Install\Tunnel\TunnelException;
use App\Support\Install\TunnelProvider;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /ipc/v1/remote-access/disable (IPC contract §6.7): `disable()`. Not
 * elevated (it only reduces exposure); gated by `disableRemoteAccess`.
 * Handlers the app did not create are listed, never removed (ADR-006 D2).
 * Writes `audit_events{action: remote_access.disabled, via: ipc}`.
 */
final class RemoteAccessDisableController extends Controller
{
    public function __invoke(Request $request, TunnelProvider $tunnel, AuditLog $audit, IpcResponder $responder): Response
    {
        IpcBody::validate($request, []);

        $principal = IpcRequest::principal($request);
        $principal->authorize(OwnerAbilities::DISABLE_REMOTE_ACCESS);

        try {
            $result = $tunnel->disable();
        } catch (TunnelException $failure) {
            throw IpcException::stateConflict(['state' => $failure->status?->state->name, 'reason' => $failure->reason->value]);
        }

        $audit->record('remote_access.disabled', $principal->actor(), $result->succeeded ? AuditLog::OUTCOME_OK : AuditLog::OUTCOME_FAILED, context: [
            ...$principal->context(),
            'request_id' => IpcRequest::requestId($request),
            'remaining_handlers' => count($result->leftoverHandlers),
        ]);

        return $responder->success($request, [
            'state' => $result->status->state->name,
            'origin' => null,
            'remaining_handlers' => $result->leftoverHandlers,
        ]);
    }
}
