<?php

declare(strict_types=1);

namespace App\Security\Authorization\Maintenance;

use InvalidArgumentException;

/**
 * The audit actor shape of the `TenantWideMaintenance` category (issue #127):
 * `maintenance:<Operation>`.
 *
 * ## Why a fourth prefix rather than reusing `job:`
 *
 * `audit_events.actor` already carries `user:<ulid>` for a person and `job:<Name>` for a
 * process. The obvious move is to write `job:AppKeyRotation` and be done — and S2-04 did
 * exactly that. It is not enough, because **`job:` does not mean "nobody asked"**. The
 * repository uses it for paths a person is standing in front of: `job:CreateOwnerForm`
 * is a Livewire submit, `job:IpcCreateOwner` is a menu-bar click, `job:PasskeyLogin` is
 * somebody signing in. Those rows are `job:` because no `users.id` existed *yet*, not
 * because no user was involved. So a reader who filters the trail on `job:` gets a mix
 * of "the system did this on its own" and "a user did this before they had an id", which
 * is the distinction T-46 wants the trail to make.
 *
 * `maintenance:` is a shape no user-facing surface produces, so the question "did
 * anything reach every tenant's rows with nobody asking, and when" is a `LIKE
 * 'maintenance:%'` away.
 *
 * ## The §3.1 provenance grammar is deliberately *not* widened
 *
 * `packaging/fixtures/crm/crm.schema.json` pins the `provenance.actor` of a business row
 * to `user:` / `job:` / `ai:`, and that stays pinned: a maintenance operation never
 * **authors** a business row, so admitting `maintenance:` into the authorship grammar
 * would only create the possibility this category forbids. `AuditLog::provenance()`
 * therefore maps a maintenance actor onto `job:<Operation>` with origin `sync` for the
 * provenance object, while `audit_events.actor` — which the fixture grammar does not
 * govern — carries the distinct shape. The distinction lives in the column that is about
 * *who acted*; the grammar stays about *who wrote the row*.
 */
final class MaintenanceActor
{
    /** The actor prefix. `AuditLog` recognises it; nothing else writes it. */
    public const string PREFIX = 'maintenance:';

    /**
     * A bare identifier, which is what a class basename is. Anything else — a colon, a
     * slash, a ULID — is refused, so a member cannot produce an actor that reads as a
     * person or as another grammar's actor.
     */
    private const string OPERATION_PATTERN = '/^[A-Za-z][A-Za-z0-9]*$/';

    /** @throws InvalidArgumentException the operation does not name itself with a bare identifier */
    public static function for(TenantWideMaintenance $operation): string
    {
        return self::named($operation->maintenanceOperation());
    }

    /** @throws InvalidArgumentException */
    public static function named(string $operation): string
    {
        if (preg_match(self::OPERATION_PATTERN, $operation) !== 1) {
            throw new InvalidArgumentException(
                "A tenant-wide maintenance operation names itself with a bare identifier; got \"{$operation}\".",
            );
        }

        return self::PREFIX.$operation;
    }

    public static function isMaintenance(string $actor): bool
    {
        return str_starts_with($actor, self::PREFIX)
            && preg_match(self::OPERATION_PATTERN, substr($actor, strlen(self::PREFIX))) === 1;
    }

    /** The operation an actor names, or null if the actor is not a maintenance actor. */
    public static function operationOf(string $actor): ?string
    {
        return self::isMaintenance($actor) ? substr($actor, strlen(self::PREFIX)) : null;
    }
}
