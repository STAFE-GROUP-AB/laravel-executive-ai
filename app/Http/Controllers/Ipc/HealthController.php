<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Ipc\IpcResponder;
use App\Support\Health\HealthService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /ipc/v1/health (IPC contract §6.1): the health contract's `ready`
 * document, unchanged, inside the success envelope; 200 when ready, 503
 * otherwise with the same body shape and no error code. Scope only
 * (`menubar` and `supervisor`), no gate: it is the Supervisor's probe.
 */
final class HealthController extends Controller
{
    public function __invoke(Request $request, HealthService $health, IpcResponder $responder): Response
    {
        $document = $health->ready();

        return $responder->success($request, $document->toArray(), $document->statusCode());
    }
}
