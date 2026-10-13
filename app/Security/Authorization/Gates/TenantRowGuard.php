<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * The one read path into a tenant table that has no Eloquent model yet.
 *
 * S2-03 landed twelve governance tables — `email_connections`,
 * `external_accounts`, `sync_cursors`, `mcp_servers` and the rest — with an
 * `organization_id` and no model, so there is no policy for Laravel to
 * discover and nothing for `Gate::authorize('view', $row)` to resolve. A
 * repository written against one of those tables before its model exists still
 * has to run a check, and this is the check: read the row's `organization_id`
 * by id, put *that* to the `accessOrganization` gate, and refuse when the row
 * belongs to another organization or does not exist.
 *
 * Two properties make this safe to keep rather than a stopgap:
 *
 * 1. the organization is read from the row, never taken from the caller, so an
 *    id in a request body can neither name a tenant nor widen one (ipc-contract
 *    §11.4 applied to the PWA);
 * 2. a missing row and a foreign row are the same refusal, so the refusal
 *    cannot be used to probe which ids exist.
 *
 * `PolicySurfaceArchTest` requires a *policy* the moment one of these tables
 * grows a model, so this never becomes the comfortable way to skip writing one.
 */
final class TenantRowGuard
{
    public function __construct(
        private readonly Gate $gate,
        private readonly ConnectionInterface $connection,
        private readonly SurfaceContext $surfaces,
    ) {}

    public function allows(User $user, string $table, string $id): bool
    {
        return $this->gate->forUser($user)->allows(
            Abilities::ACCESS_ORGANIZATION,
            [$this->organizationIdOf($table, $id)],
        );
    }

    /** @throws AuthorizationException the row belongs to another organization, or does not exist */
    public function authorize(User $user, string $table, string $id): void
    {
        $this->gate->forUser($user)->authorize(
            Abilities::ACCESS_ORGANIZATION,
            [$this->organizationIdOf($table, $id)],
        );
    }

    /**
     * The same check from a surface without a request — a queued job reading a
     * governance row, or a tool call — so the audit row names the right path.
     *
     * @throws AuthorizationException
     */
    public function authorizeOn(Surface $surface, User $user, string $table, string $id): void
    {
        $this->surfaces->within($surface, function () use ($user, $table, $id): void {
            $this->authorize($user, $table, $id);
        });
    }

    /**
     * Null when the row is absent, which the gate refuses — see property 2 in
     * the class docblock.
     */
    private function organizationIdOf(string $table, string $id): ?string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Not a table name: {$table}");
        }

        $organizationId = $this->connection->table($table)->where('id', $id)->value('organization_id');

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }
}
