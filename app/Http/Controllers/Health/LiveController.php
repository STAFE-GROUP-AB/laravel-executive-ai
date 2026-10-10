<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Support\Health\HealthService;
use Illuminate\Http\JsonResponse;

/**
 * GET /health/live — `{"status":"ok"}` whenever the kernel can serve a
 * request. Touches no database, cache or filesystem (health contract §1),
 * so a locked or migrating database never causes a supervisor restart loop.
 */
final class LiveController extends Controller
{
    public function __invoke(HealthService $health): JsonResponse
    {
        return response()
            ->json($health->live())
            ->withHeaders(HealthHeaders::all());
    }
}
