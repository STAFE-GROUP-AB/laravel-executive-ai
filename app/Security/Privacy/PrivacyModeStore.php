<?php

declare(strict_types=1);

namespace App\Security\Privacy;

use App\AI\Routing\PrivacyMode;
use App\Models\Organization;
use App\Models\User;
use App\Security\Audit\AuditLog;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Authorization\Reauthentication\CriticalAction;
use App\Security\Authorization\Reauthentication\ReauthenticationRequired;
use App\Security\Authorization\Reauthentication\ReauthProof;
use App\Support\Data\Provenance;
use App\Support\Data\ProvenanceOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The privacy mode's reader and its one writer — the **rule** half of
 * OPEN-QUESTIONS **A18**, on the storage S2-03 landed.
 *
 * The answer this class is, in four parts (threat model **T-68**):
 *
 * - **Where it lives.** `privacy_policies`, append-only, at most one live row
 *   per workspace. Not `config/lea.php` and not an environment variable:
 *   ADR-001 D4 removed the `.env`, so a stored row is the only place a change
 *   can be attributed to someone. `lea.privacy_mode` stays the install-time
 *   seed and the pre-migration fallback, and the stored row always wins.
 * - **Who may change it.** The workspace owner, and only through a signed-in
 *   PWA session — `OwnerAbilities::isOwner()` is the same check the IPC
 *   abilities use, so the menu bar cannot change the mode at all.
 * - **Whether a downgrade needs re-authentication.** Yes, and only a downgrade.
 *   A change that **widens** where model traffic may go — a mode with greater
 *   breadth, or `LOCAL_ONLY_ALLOW_LAN` turning on — needs a fresh
 *   `CriticalAction::ChangePrivacyMode` proof (S2-05) **and** an explicit UI
 *   re-confirmation. Tightening needs neither: a control that makes it
 *   laborious to become stricter is a control that gets left loose.
 * - **What it writes.** One `audit_events` row, action `privacy.mode_changed`,
 *   whose id is stored on the new policy row; the superseded row keeps its own.
 *   The audit event and the policy row are written in one transaction, so a
 *   mode in force without an audit trail is not a state this table can hold.
 */
final class PrivacyModeStore
{
    public const string TABLE = 'privacy_policies';

    public const string AUDIT_ACTION = 'privacy.mode_changed';

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * The policy in force. Falls back to the configured seed when no row has
     * been written, and to the strictest mode when the configured value cannot
     * be read at all (`PrivacyMode::fromConfigValue()` already falls closed).
     */
    public function active(?string $organizationId = null): PrivacyPolicyState
    {
        try {
            $row = $this->liveRow($organizationId);
        } catch (QueryException) {
            // A boot before migrations, or a profile whose governance tables are
            // not there yet. The configured seed is the answer, not an error.
            return $this->fromConfig();
        }

        if ($row === null) {
            return $this->fromConfig();
        }

        $mode = PrivacyMode::fromConfigValue($row->mode);

        return new PrivacyPolicyState(
            mode: $mode,
            allowLan: (bool) $row->allow_lan && $mode->supportsLanToggle(),
            source: (string) $row->source,
            policyId: (string) $row->id,
            changedByUserId: $this->asString($row->changed_by_user_id),
            auditEventId: $this->asString($row->audit_event_id),
            reauthenticatedAt: $this->asTime($row->reauthenticated_at),
            effectiveFrom: $this->asTime($row->effective_from),
        );
    }

    /** The mode alone, for the surfaces that only report it. */
    public function activeMode(?string $organizationId = null): PrivacyMode
    {
        return $this->active($organizationId)->mode;
    }

    /**
     * Whether this change would widen where model traffic may go — the thing
     * T-68 calls a downgrade and the only thing this store gates.
     */
    public function widens(PrivacyPolicyState $current, PrivacyMode $mode, bool $allowLan): bool
    {
        return $mode->wouldWiden($current->mode) || ($allowLan && ! $current->allowLan);
    }

    /**
     * Record a new policy. Returns the state now in force.
     *
     * @param  bool  $confirmed  the owner re-confirmed the widening in the UI, not merely submitted it
     *
     * @throws PrivacyModeChangeRefused
     * @throws ReauthenticationRequired
     */
    public function change(
        User $actor,
        PrivacyMode $mode,
        bool $allowLan = false,
        ?string $reason = null,
        ?ReauthProof $proof = null,
        bool $confirmed = false,
        ?string $organizationId = null,
    ): PrivacyPolicyState {
        if (! OwnerAbilities::isOwner($actor)) {
            throw new PrivacyModeChangeRefused(PrivacyModeChangeRefused::NOT_OWNER);
        }

        if ($allowLan && ! $mode->supportsLanToggle()) {
            throw new PrivacyModeChangeRefused(PrivacyModeChangeRefused::LAN_TOGGLE_UNSUPPORTED);
        }

        $organizationId ??= $this->soleOrganizationId();

        if ($organizationId === null) {
            throw new PrivacyModeChangeRefused(PrivacyModeChangeRefused::NO_WORKSPACE);
        }

        $current = $this->active($organizationId);
        $widens = $this->widens($current, $mode, $allowLan);
        $reauthenticatedAt = null;

        if ($widens) {
            if (! $confirmed) {
                throw new PrivacyModeChangeRefused(PrivacyModeChangeRefused::CONFIRMATION_REQUIRED);
            }

            $reauthenticatedAt = $this->requireProof($proof)->verifiedAt;
        }

        $now = CarbonImmutable::now('UTC');
        $policyId = (string) Str::ulid();

        DB::transaction(function () use ($actor, $current, $mode, $allowLan, $reason, $proof, $widens, $reauthenticatedAt, $organizationId, $now, $policyId): void {
            $event = $this->audit->record(
                action: self::AUDIT_ACTION,
                actor: "user:{$actor->id}",
                outcome: AuditLog::OUTCOME_OK,
                subjectType: self::TABLE,
                subjectId: $policyId,
                context: [
                    'from_mode' => $current->configValue(),
                    'to_mode' => $mode->configValue(),
                    'from_allow_lan' => $current->allowLan,
                    'to_allow_lan' => $allowLan,
                    'widens' => $widens,
                    'reauth_method' => $proof?->method->value,
                    'reason' => $reason,
                ],
                organizationId: $organizationId,
            );

            if ($current->policyId !== null) {
                DB::table(self::TABLE)
                    ->where('id', $current->policyId)
                    ->whereNull('superseded_at')
                    ->update(['superseded_at' => $now, 'updated_at' => $now]);
            }

            DB::table(self::TABLE)->insert([
                'id' => $policyId,
                'organization_id' => $organizationId,
                'mode' => $mode->configValue(),
                'allow_lan' => $allowLan,
                'source' => PrivacyPolicyState::SOURCE_OWNER,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 200),
                'changed_by_user_id' => $actor->id,
                'audit_event_id' => $event->id,
                'reauthenticated_at' => $reauthenticatedAt,
                'effective_from' => $now,
                'superseded_at' => null,
                'sensitivity_class' => 'internal',
                'provenance' => json_encode(
                    Provenance::user((string) $actor->id),
                    JSON_THROW_ON_ERROR,
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $this->active($organizationId);
    }

    /**
     * The install-time row: the strictest mode, no owner, no proof, and
     * `source = install_default` so the absence of attribution is a recorded
     * fact rather than a gap. Idempotent — a workspace that already has a live
     * policy keeps it.
     */
    public function seedInstallDefault(string $organizationId, ?PrivacyMode $mode = null): PrivacyPolicyState
    {
        if ($this->liveRow($organizationId) !== null) {
            return $this->active($organizationId);
        }

        $now = CarbonImmutable::now('UTC');

        DB::table(self::TABLE)->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'mode' => ($mode ?? PrivacyMode::LocalOnly)->configValue(),
            'allow_lan' => false,
            'source' => PrivacyPolicyState::SOURCE_INSTALL_DEFAULT,
            'reason' => null,
            'changed_by_user_id' => null,
            'audit_event_id' => null,
            'reauthenticated_at' => null,
            'effective_from' => $now,
            'superseded_at' => null,
            'sensitivity_class' => 'internal',
            'provenance' => json_encode(
                Provenance::job('PrivacyModeStore', ProvenanceOrigin::Manual),
                JSON_THROW_ON_ERROR,
            ),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->active($organizationId);
    }

    /**
     * The proof, or a refusal naming which part of it was missing. A proof for
     * another action is not a proof for this one, and an expired one is not a
     * proof at all — `Reauthenticator::assert()` already spends it, so this is
     * the narrow re-check of what the caller handed over.
     *
     * @throws ReauthenticationRequired
     */
    private function requireProof(?ReauthProof $proof): ReauthProof
    {
        if ($proof === null) {
            throw new ReauthenticationRequired(CriticalAction::ChangePrivacyMode, ReauthenticationRequired::MISSING);
        }

        if ($proof->action !== CriticalAction::ChangePrivacyMode) {
            throw new ReauthenticationRequired(CriticalAction::ChangePrivacyMode, ReauthenticationRequired::ACTION_MISMATCH);
        }

        if ($proof->expiresAt->isBefore(CarbonImmutable::now('UTC'))) {
            throw new ReauthenticationRequired(CriticalAction::ChangePrivacyMode, ReauthenticationRequired::EXPIRED);
        }

        return $proof;
    }

    private function liveRow(?string $organizationId): ?object
    {
        $query = DB::table(self::TABLE)->whereNull('superseded_at');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return $query->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /**
     * The single-workspace install's organization. The product is one workspace
     * per Mac (§3.1), so a caller that does not name one gets the only one; a
     * second workspace makes the argument mandatory rather than ambiguous.
     */
    private function soleOrganizationId(): ?string
    {
        try {
            $id = Organization::query()->orderBy('created_at')->orderBy('id')->value('id');
        } catch (Throwable) {
            return null;
        }

        return is_string($id) ? $id : null;
    }

    private function fromConfig(): PrivacyPolicyState
    {
        return new PrivacyPolicyState(
            mode: PrivacyMode::fromConfigValue(config('lea.privacy_mode')),
            source: PrivacyPolicyState::SOURCE_FALLBACK,
        );
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function asTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
