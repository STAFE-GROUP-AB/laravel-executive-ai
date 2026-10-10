<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Support\Health\HealthService;
use Illuminate\Http\JsonResponse;

/**
 * GET /health/ready — the §12.2 core-ready document. 200 when `ready`,
 * 503 otherwise, same body shape (health contract §1). S1-08 puts this
 * route behind IpcAuthenticate; S1-01 restricts it to loopback.
 */
final class ReadyController extends Controller
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $document = $health->ready();

        return response()
            ->json($document->toArray(), $document->statusCode())
            ->withHeaders(HealthHeaders::all());
    }
}
