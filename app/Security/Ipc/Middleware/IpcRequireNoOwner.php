<?php

declare(strict_types=1);

namespace App\Security\Ipc\Middleware;

use App\Security\Audit\AuditLog;
use App\Security\Authorization\CreateOwner;
use App\Security\Ipc\IpcException;
use App\Security\Ipc\IpcRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The authorization of `POST /ipc/v1/owner` (IPC contract §2.1, §6.9), and
 * the only route in the contract that does not run an owner-bound gate —
 * because before CREATE_OWNER there is no owner to bind one to.
 *
 * What stands in for the gate is this middleware plus the one that precedes
 * it, and the two are only an authorization *together*:
 *
 *  1. `IpcAuthenticate:menubar` has already proved a live `menubar` session
 *     — our signed app, paired through the Supervisor over XPC under the
 *     Team ID requirement, over loopback, with a signed request inside the
 *     time window (§3, §4, §5.2).
 *  2. **No owner exists.** This middleware re-reads that fact from the
 *     database on every request, so the window in which the route does
 *     anything at all is the window between the first boot and the first
 *     owner — and it closes permanently, for every caller, the moment an
 *     owner exists (threat model T-72).
 *
 * The refusal is `409` / `owner_exists` and is audited as
 * `auth.owner_create_refused` with `via: ipc`, the same action
 * `CreateOwner` writes for the other two doors (auth.md §2), so a repeated
 * install leaves a trail whichever door it knocked on. `CreateOwner`'s own
 * check inside `Cache::lock('lea:create-owner')` stays the authority on the
 * race; this check is the cheap, auditable door, not a replacement for it.
 */
final class IpcRequireNoOwner
{
    public function __construct(
        private readonly CreateOwner $createOwner,
        private readonly AuditLog $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $existing = $this->createOwner->existingOwner();

        if ($existing === null) {
            return $next($request);
        }

        $principal = IpcRequest::principal($request);

        $this->audit->record(
            'auth.owner_create_refused',
            $principal->actor(),
            AuditLog::OUTCOME_REFUSED,
            'membership',
            $existing->id,
            [
                ...$principal->context(),
                'reason' => 'owner_exists',
                'request_id' => IpcRequest::requestId($request),
            ],
            $existing->organization_id,
        );

        throw IpcException::ownerExists();
    }
}
