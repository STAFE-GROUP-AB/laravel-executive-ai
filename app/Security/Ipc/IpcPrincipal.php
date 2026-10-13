<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * §2: a verified session resolves to the synthetic principal
 * `IpcClient { client, session_id }` bound to the owner account (v1 is
 * single-owner), and every operation runs its gate as
 * `Gate::forUser($owner)->authorize(<ability>, [..., ['via' => 'ipc']])`.
 * Identity never comes from request content (§11.4).
 *
 * **Before CREATE_OWNER there is no owner to bind to** (§2.1). That is not
 * an edge case to be discovered: the owner is resolved lazily, `owner()`
 * answers null, `actor()` degrades to the `job:IpcServer` provenance actor,
 * and `authorize()` **fails closed with `policy_denied`** — so every gated
 * operation in §6 is refused on an ownerless core, by design and not by
 * accident. The single operation that must work before an owner exists
 * (`POST /ipc/v1/owner`) therefore never calls `authorize()`; it is
 * authorised by `Middleware\IpcRequireNoOwner`, which refuses as soon as an
 * owner does exist. `IpcPolicyArchTest` pins both halves of that rule.
 */
final class IpcPrincipal
{
    private bool $ownerResolved = false;

    private ?User $owner = null;

    public function __construct(public readonly IpcSession $session) {}

    public function client(): IpcClient
    {
        return $this->session->client;
    }

    public function sessionId(): string
    {
        return $this->session->sessionId;
    }

    /** The owner account, or null on a fresh install before CREATE_OWNER. */
    public function owner(): ?User
    {
        if ($this->ownerResolved) {
            return $this->owner;
        }

        $this->ownerResolved = true;

        $membership = Membership::query()
            ->where('role', MembershipRole::Owner)
            ->orderBy('created_at')
            ->with('user')
            ->first();

        $this->owner = $membership?->user;

        return $this->owner;
    }

    /** The §3.1 actor string for audit events written on behalf of this principal. */
    public function actor(): string
    {
        $owner = $this->owner();

        return $owner === null ? 'job:IpcServer' : "user:{$owner->id}";
    }

    /** @return array{via: string, client: string, session_id: string} */
    public function context(): array
    {
        return ['via' => 'ipc', 'client' => $this->client()->value, 'session_id' => $this->sessionId()];
    }

    /**
     * §11.1: the same gate the PWA runs, as the owner, with `via: ipc` as the
     * last argument. No owner, or a denial, is 403 / policy_denied — the
     * deliberate pre-owner answer for every gated operation (§2.1).
     *
     * @param  list<mixed>  $arguments
     *
     * @throws IpcException policy_denied
     */
    public function authorize(string $ability, array $arguments = []): void
    {
        $owner = $this->owner();

        if ($owner === null) {
            throw IpcException::policyDenied();
        }

        try {
            Gate::forUser($owner)->authorize($ability, [...$arguments, $this->context()]);
        } catch (AuthorizationException) {
            throw IpcException::policyDenied();
        }
    }
}
