<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Ipc\IpcBody;
use App\Security\Ipc\IpcResponder;
use App\Support\Health\Heartbeats;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /ipc/v1/heartbeat (IPC contract §6.5): the only authenticated signal
 * the `menubar_integration` health check reads. The route is scoped to the
 * `menubar` client, so a `supervisor` token never reaches this code
 * (403 / forbidden_scope in IpcAuthenticate). Only the receive time and the
 * small non-secret attributes are stored.
 */
final class HeartbeatController extends Controller
{
    public function __invoke(Request $request, Heartbeats $heartbeats, IpcResponder $responder): Response
    {
        $body = IpcBody::validate($request, [
            'client' => ['required', 'string', 'in:menubar'],
            'client_version' => ['required', 'string', 'max:64'],
            'contract_version' => ['required', 'string', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+$/'],
            'panel_open' => ['required', 'boolean'],
            'since' => ['required', 'string', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s\Z'],
            'quitting' => ['boolean'],
        ]);

        $now = CarbonImmutable::now('UTC');

        $heartbeats->record(Heartbeats::MENUBAR, [
            'client_version' => (string) $body['client_version'],
            'panel_open' => (bool) $body['panel_open'],
            'quitting' => (bool) ($body['quitting'] ?? false),
        ], $now);

        return $responder->success($request, [
            'received_at' => IpcResponder::serverTime($now),
            'next_heartbeat_in_seconds' => (int) config('ipc.heartbeat.next_in_seconds'),
            'threshold_seconds' => (int) config('health.thresholds.menubar_integration'),
            'server' => [
                'app_version' => (string) config('app.version', '0.0.0'),
                'runtime_version' => (string) config('ipc.server.runtime_version'),
            ],
        ]);
    }
}
