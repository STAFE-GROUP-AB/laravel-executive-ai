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
 * POST /ipc/v1/ai/resume (IPC contract §6.4): clears the global
 * cancellation token. Idempotent. Gate: `resumeAi` (the policy denies it
 * while the automation broker is locked, ADR-003 D3.2, once the broker
 * exists). Writes `audit_events{action: ai.resumed, via: ipc}`.
 */
final class AiResumeController extends Controller
{
    public function __invoke(Request $request, AiPauseSwitch $switch, AuditLog $audit, IpcResponder $responder): Response
    {
        IpcBody::validate($request, []);

        $principal = IpcRequest::principal($request);
        $principal->authorize(OwnerAbilities::RESUME_AI);

        $status = $switch->resume();

        $audit->record('ai.resumed', $principal->actor(), AuditLog::OUTCOME_OK, context: [
            ...$principal->context(),
            'request_id' => IpcRequest::requestId($request),
            'changed' => $status->changed,
        ]);

        return $responder->success($request, $status->toIpcData((string) config('app.timezone', 'UTC')));
    }
}
