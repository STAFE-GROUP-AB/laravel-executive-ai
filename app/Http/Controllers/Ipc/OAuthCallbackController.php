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
use App\Security\Ipc\OAuth\OAuthCallbackHandler;
use App\Security\Ipc\OAuth\OAuthStateInvalidException;
use App\Security\Secrets\Redactor;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * POST /ipc/v1/oauth/callback (IPC contract §6.6, ADR-003 D4): the GUI
 * forwards exactly `code` and `state`. Both are registered with the
 * Redactor for the rest of the process before anything else runs, so a
 * careless handler that embeds them in an exception message still leaves
 * nothing in any sink; an unexpected failure is reported by class name
 * only. Gate: `completeOAuth`. Writes `audit_events{action: oauth.completed}`.
 */
final class OAuthCallbackController extends Controller
{
    public function __invoke(Request $request, OAuthCallbackHandler $handler, Redactor $redactor, AuditLog $audit, IpcResponder $responder): Response
    {
        $body = IpcBody::validate($request, [
            'state' => ['required', 'string', 'min:16', 'max:512'],
            'code' => ['required', 'string', 'min:1', 'max:2048'],
        ]);

        $state = (string) $body['state'];
        $code = (string) $body['code'];
        $redactor->register($state);
        $redactor->register($code);

        $principal = IpcRequest::principal($request);
        $principal->authorize(OwnerAbilities::COMPLETE_OAUTH);

        try {
            $result = $handler->complete($state, $code, $principal);
        } catch (OAuthStateInvalidException) {
            throw IpcException::oauthStateInvalid();
        } catch (Throwable $failure) {
            report(new RuntimeException('OAuth callback exchange failed: '.$failure::class));

            throw IpcException::internal();
        }

        $audit->record('oauth.completed', $principal->actor(), $result->status === 'failed' ? AuditLog::OUTCOME_FAILED : AuditLog::OUTCOME_OK, 'external_account', $result->connectionId, [
            ...$principal->context(),
            'request_id' => IpcRequest::requestId($request),
            'status' => $result->status,
            'provider' => $result->provider,
            'error_reason' => $result->errorReason,
        ]);

        return $responder->success($request, [
            'status' => $result->status,
            'connection_id' => $result->connectionId,
            'provider' => $result->provider,
            'open_url' => $result->openPath === null ? null : IpcResponder::originUrl().'/'.ltrim($result->openPath, '/'),
            'error_reason' => $result->errorReason,
        ]);
    }
}
