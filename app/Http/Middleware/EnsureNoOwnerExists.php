<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Security\Authorization\CreateOwner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The in-app CREATE_OWNER screen exists only until an owner exists; after
 * that the route is a 404, so a repeated install has no page through which
 * to create a second owner (threat model T-72).
 */
final class EnsureNoOwnerExists
{
    public function __construct(private readonly CreateOwner $createOwner) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->createOwner->ownerExists()) {
            abort(404);
        }

        return $next($request);
    }
}
