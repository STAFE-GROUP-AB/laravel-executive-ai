<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Ipc\IpcException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every other path under /ipc/v1 — including the reserved §6.8 service
 * routes, which are deliberately not HTTP operations — answers a signed
 * 404 / not_found envelope instead of the framework's page. No gate: it
 * never reaches the domain.
 */
final class ReservedController extends Controller
{
    public function __invoke(Request $request): Response
    {
        throw IpcException::notFound();
    }
}
