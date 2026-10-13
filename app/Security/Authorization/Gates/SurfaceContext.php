<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/**
 * Which of the T-73 paths the current authorization decision is being made on.
 *
 * A request answers for itself (`/ipc/…` is the IPC, anything else under the
 * `web` group is the PWA). A queued job and a tool call have no request, so
 * `AuthorizesQueuedWork` and `ToolAuthorizer` wrap their check in `within()`
 * and the stack answers instead. It is a stack rather than a flag because a
 * tool call happens *inside* a job: the innermost frame is the one the audit
 * row should name.
 *
 * Bound as a singleton (`AuthorizationServiceProvider`) rather than kept in a
 * static, so a test can resolve it, assert on it and start each case from a
 * clean container.
 */
final class SurfaceContext
{
    /** @var list<Surface> */
    private array $stack = [];

    public function __construct(private readonly Application $app) {}

    /**
     * Run `$work` with `$surface` as the current surface, restoring the
     * previous frame even when the work throws — a refusal is the common case
     * here, and a refusal must not leave the stack naming the wrong path.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public function within(Surface $surface, Closure $work): mixed
    {
        $this->stack[] = $surface;

        try {
            return $work();
        } finally {
            array_pop($this->stack);
        }
    }

    public function current(): Surface
    {
        $pushed = end($this->stack);

        if ($pushed instanceof Surface) {
            return $pushed;
        }

        return $this->fromRequest();
    }

    /**
     * `runningInConsole()` alone cannot answer this: the test suite and the
     * queue worker are both console processes, and an HTTP test dispatching a
     * request through the kernel is the PWA surface, not the console. So the
     * request decides whenever there is a real one, and the console answers
     * for the placeholder request Laravel builds from `argv` (path `/`).
     */
    private function fromRequest(): Surface
    {
        if (! $this->app->bound('request')) {
            return Surface::Console;
        }

        try {
            $path = $this->app->make('request')->path();
        } catch (Throwable) {
            return Surface::Console;
        }

        if ($path === '/' && $this->app->runningInConsole()) {
            return Surface::Console;
        }

        return str_starts_with($path, 'ipc/') ? Surface::Ipc : Surface::Pwa;
    }
}
