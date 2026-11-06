<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

/**
 * Every named ability in the application, in one place (blueprint §11).
 *
 * `OwnerAbilities` names the IPC contract's §6 operations; this class names
 * everything else: the seven CRUD abilities each §3.1 business-object policy
 * answers, the tenant gate a table without an Eloquent model is read through,
 * and one ability per PWA page and per Livewire action.
 *
 * Two lists carry weight beyond documentation:
 *
 * - `READ_ONLY` is what `AuthorizationAuditor` consults. A refusal is always
 *   audited; an *allow* is audited only when the ability can change something,
 *   so the audit table records every mutation and every denial (T-46) without
 *   a row per page view. An ability that is not listed here counts as
 *   mutating — a new ability is audited until someone states otherwise, never
 *   the reverse.
 * - `CRUD` is what `PolicySurfaceArchTest` requires every business-object
 *   policy to answer.
 */
final class Abilities
{
    public const string VIEW_ANY = 'viewAny';

    public const string VIEW = 'view';

    public const string CREATE = 'create';

    public const string UPDATE = 'update';

    public const string DELETE = 'delete';

    public const string RESTORE = 'restore';

    public const string FORCE_DELETE = 'forceDelete';

    /**
     * The tenant decision for a row in a table that has no Eloquent model yet
     * (the S2-03 governance tables). `TenantRowGuard` is the only caller: it
     * reads the row's `organization_id` by id and puts it to this gate, so a
     * repository written before its model exists still runs one check.
     */
    public const string ACCESS_ORGANIZATION = 'accessOrganization';

    public const string VIEW_HOME = 'viewHome';

    public const string VIEW_BRIEFS = 'viewBriefs';

    /**
     * The four §1.2 CRM screens (S2-09). One ability rather than four, because
     * they ask the same narrower question the other page abilities ask — "is
     * this a signed-in user of this install" — and the tenant decision stays
     * with the row: `CrmRepository` scopes every query and the CRM policies
     * answer for each record. A second ability per screen would be four copies
     * of one question to keep in step.
     */
    public const string VIEW_CRM = 'viewCrm';

    public const string VIEW_AI_SETTINGS = 'viewAiSettings';

    public const string VIEW_SECURITY_SETTINGS = 'viewSecuritySettings';

    public const string REVOKE_DEVICE = 'revokeDevice';

    public const string REGISTER_PASSKEY = 'registerPasskey';

    public const string REMOVE_PASSKEY = 'removePasskey';

    public const string REAUTHENTICATE = 'reauthenticate';

    /** @var list<string> the abilities every business-object policy answers */
    public const array CRUD = [
        self::VIEW_ANY,
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
        self::RESTORE,
        self::FORCE_DELETE,
    ];

    /**
     * Abilities defined as gates rather than as policy methods (`SurfaceGates`).
     *
     * @var list<string>
     */
    public const array SURFACES = [
        self::ACCESS_ORGANIZATION,
        self::VIEW_HOME,
        self::VIEW_BRIEFS,
        self::VIEW_CRM,
        self::VIEW_AI_SETTINGS,
        self::VIEW_SECURITY_SETTINGS,
        self::REVOKE_DEVICE,
        self::REGISTER_PASSKEY,
        self::REMOVE_PASSKEY,
        self::REAUTHENTICATE,
    ];

    /**
     * Abilities that cannot change anything. An allow is not audited; a
     * refusal still is. Anything absent from this list is treated as
     * mutating, including an ability added tomorrow.
     *
     * @var list<string>
     */
    public const array READ_ONLY = [
        self::VIEW_ANY,
        self::VIEW,
        self::ACCESS_ORGANIZATION,
        self::VIEW_HOME,
        self::VIEW_BRIEFS,
        self::VIEW_CRM,
        self::VIEW_AI_SETTINGS,
        self::VIEW_SECURITY_SETTINGS,
    ];

    public static function isMutating(string $ability): bool
    {
        return ! in_array($ability, self::READ_ONLY, true);
    }
}
