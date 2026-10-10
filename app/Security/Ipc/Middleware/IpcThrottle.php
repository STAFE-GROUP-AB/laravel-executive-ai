<?php

declare(strict_types=1);

namespace App\Security\Ipc\Middleware;

use App\Security\Ipc\IpcException;
use App\Security\Ipc\IpcRequest;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * §4.4 step 8 / §9: per-operation and all-routes limits per session, keyed
 * by `session_id` in Laravel's RateLimiter; 429 carries `Retry-After`. Runs
 * after IpcAuthenticate, so only a verified session can consume budget. The
 * operation name must be in config/ipc.php `rate_limits`; an unknown one
 * fails closed.
 */
final class IpcThrottle
{
    private const int DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $session = IpcRequest::session($request);

        if ($session === null) {
            throw new LogicException('IpcThrottle requires IpcAuthenticate before it.');
        }

        $limits = [
            "ipc:limit:{$operation}:{$session->sessionId}" => $this->limitFor($operation),
            "ipc:limit:all:{$session->sessionId}" => $this->limitFor('all'),
        ];

        foreach ($limits as $key => $max) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw IpcException::rateLimited($this->limiter->availableIn($key));
            }
        }

        foreach (array_keys($limits) as $key) {
            $this->limiter->hit($key, self::DECAY_SECONDS);
        }

        return $next($request);
    }

    private function limitFor(string $operation): int
    {
        $limit = config("ipc.rate_limits.{$operation}");

        if (! is_int($limit) || $limit < 1) {
            throw new LogicException("No IPC rate limit configured for operation `{$operation}`.");
        }

        return $limit;
    }
}
