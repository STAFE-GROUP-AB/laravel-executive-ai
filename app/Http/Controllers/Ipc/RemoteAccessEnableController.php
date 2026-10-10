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
use App\Support\Cancellation\NeverCancelled;
use App\Support\Install\Tunnel\TunnelConfig;
use App\Support\Install\Tunnel\TunnelException;
use App\Support\Install\Tunnel\TunnelState;
use App\Support\Install\TunnelProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /ipc/v1/remote-access/enable (IPC contract §6.7): elevated (§7.3,
 * enforced by IpcRequireElevation before this runs) and gated by
 * `enableRemoteAccess`. Re-runs `inspect()` and refuses with 409 /
 * state_conflict unless the state is Ready — or Drifted with
 * replace_existing — and `confirmed_origin` equals the origin derived from
 * the tailnet DNS name; never from FunnelDetected. `confirmed_origin` is
 * compared, never passed to a CLI. Writes
 * `audit_events{action: remote_access.enabled, via: ipc}`.
 */
final class RemoteAccessEnableController extends Controller
{
    public function __invoke(Request $request, TunnelProvider $tunnel, AuditLog $audit, IpcResponder $responder): Response
    {
        $body = IpcBody::validate($request, [
            'confirmed_origin' => ['required', 'string', 'regex:/^https:\/\/[a-z0-9-]+\.[a-z0-9-]+\.ts\.net$/'],
            'replace_existing' => ['required', 'boolean'],
        ]);

        $principal = IpcRequest::principal($request);
        $principal->authorize(OwnerAbilities::ENABLE_REMOTE_ACCESS);

        $status = $tunnel->inspect();
        $replace = (bool) $body['replace_existing'];
        $allowedState = $status->state === TunnelState::Ready || ($status->state === TunnelState::Drifted && $replace);

        if (! $allowedState || RemoteAccessData::origin($status) !== (string) $body['confirmed_origin']) {
            throw IpcException::stateConflict(['state' => $status->state->name, 'origin' => RemoteAccessData::origin($status)]);
        }

        try {
            $result = $tunnel->configure(
                new TunnelConfig((int) config('ipc.port'), IpcRequest::requestId($request), $replace),
                new NeverCancelled,
            );
        } catch (TunnelException $failure) {
            throw IpcException::stateConflict(['state' => $failure->status?->state->name ?? $status->state->name, 'reason' => $failure->reason->value]);
        }

        if (! $result->succeeded || $result->status->state !== TunnelState::Active || $result->status->origin === null) {
            throw IpcException::stateConflict(['state' => $result->status->state->name, 'reason' => 'verification_failed']);
        }

        $verifiedAt = CarbonImmutable::now('UTC');

        $audit->record('remote_access.enabled', $principal->actor(), AuditLog::OUTCOME_OK, context: [
            ...$principal->context(),
            'request_id' => IpcRequest::requestId($request),
            'origin' => $result->status->origin,
        ]);

        return $responder->success($request, [
            'state' => TunnelState::Active->name,
            'origin' => $result->status->origin,
            'verified_at' => IpcResponder::serverTime($verifiedAt),
        ]);
    }
}
