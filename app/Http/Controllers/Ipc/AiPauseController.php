<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\IpcBody;
use App\Security\Ipc\IpcRequest;
use App\Security\Ipc\IpcResponder;
use App\Support\Cancellation\AiPauseSwitch;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /ipc/v1/ai/pause (IPC contract §6.4): flips the global cooperative
 * cancellation token (ADR-004 D7). Idempotent; never requires elevation
 * because it only reduces activity. Gate: `pauseAi`. Writes
 * `audit_events{action: ai.paused, via: ipc}`. Not the panic stop (ADR-005
 * D1.9), which goes GUI → helper over XPC.
 */
final class AiPauseController extends Controller
{
    public function __invoke(Request $request, AiPauseSwitch $switch, AuditLog $audit, IpcResponder $responder): Response
    {
        $body = IpcBody::validate($request, [
            'reason' => ['string', 'max:200'],
        ]);

        $principal = IpcRequest::principal($request);
        $principal->authorize(OwnerAbilities::PAUSE_AI);

        $status = $switch->pause(isset($body['reason']) ? (string) $body['reason'] : null);

        $audit->record('ai.paused', $principal->actor(), AuditLog::OUTCOME_OK, context: [
            ...$principal->context(),
            'request_id' => IpcRequest::requestId($request),
            'changed' => $status->changed,
        ]);

        return $responder->success($request, $status->toIpcData((string) config('app.timezone', 'UTC')));
    }
}
