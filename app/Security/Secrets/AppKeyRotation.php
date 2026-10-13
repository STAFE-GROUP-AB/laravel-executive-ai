<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use App\Security\Audit\AuditLog;
use App\Security\Authorization\Maintenance\MaintenanceActor;
use App\Security\Authorization\Maintenance\TenantWideMaintenance;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * ADR-003 **D8**'s re-encryption pass: after `core.app-key` is rotated, every
 * `encrypted:` attribute is rewritten under the new key (S2-04, #87).
 *
 * The envelope does the hard part. `core.app-key` holds `{current, previous[]}`, the
 * Supervisor hands both to `SecretsServiceProvider`, which sets `app.key` and
 * `app.previous_keys` before the encrypter resolves (D3.3) — so the instant the new
 * key is written, **every existing row still reads**, because Laravel's encrypter
 * falls back through `previous_keys` when the current key fails to decrypt (Evidence
 * E35). Nothing is unreadable while this pass is pending, and nothing is unreadable if
 * it fails: the rotation is complete when the old key can be dropped from `previous[]`,
 * and dropping it is a separate, later act.
 *
 * Two things are rewritten per row, and the second is easy to forget:
 *
 *  1. **The ciphertext**, re-encrypted under the current key.
 *  2. **`contact_emails.address_hash`**, because the blind index is an HMAC under a
 *     key derived from the application key, so rotating the key changes every index
 *     key. Until the rewrite reaches a row, `ContactEmail::forAddress()` still finds
 *     it: that scope asks for the hash under *every* key in the envelope, the same
 *     fallback the encrypter makes for ciphertext. A rotation interrupted half-way
 *     therefore leaves lookups correct rather than leaves rows invisible.
 *
 * ## Why this is not a `ShouldQueue` job — settled by #127, not deferred
 *
 * `tests/Security/PolicySurfaceArchTest.php` (S2-06, #88) requires every `ShouldQueue`
 * class that reaches tenant data to use `AuthorizesQueuedWork` and run **as the user
 * that dispatched it** — deliberately, because "there is no privileged job identity to
 * escalate to" (that trait's own words, threat model T-73).
 *
 * A key rotation cannot run as a user, and not for want of plumbing:
 *
 *  - It must rewrite **every** organization's rows, because the key is install-wide.
 *    A dispatcher's memberships are the wrong scope by construction.
 *  - Four of the eight inventoried tables are **append-only by policy** —
 *    `ai_runs`, `tool_runs`, `source_records`, `memory_sources` — so
 *    `BusinessObjectPolicy::update()` answers *false* for them to every user, for good
 *    reason: they are evidence. A rotation that honoured that would silently skip the
 *    prompts, the tool arguments and the memory provenance, and the install would end
 *    up half under each key. A rotation that overrode it would be the privileged job
 *    identity S2-06 exists to prevent.
 *
 * So re-encryption is not a user action at all. It belongs to the same category as the
 * S2-04 migration that first wrote these columns: a storage-layer pass triggered by a
 * key change, which is why that migration is not a policy surface either. **#127 names
 * that category** — `TenantWideMaintenance`, "authorised by not being a user action",
 * [authorization.md §10](../../../docs/architecture/authorization.md) — and this class is
 * its first member. ADR-003 D8's "in a queued job" wording is corrected there and in the
 * ADR; the queue was never what made this safe, the cache lock below is, and it is the
 * same device D8 already uses to serialise token refreshes.
 *
 * Carrying the marker is not a licence. `tests/Security/MaintenanceCategoryArchTest.php`
 * holds this class to it: no route, Livewire action, tool or `ShouldQueue` class may name
 * it, it may not read `auth()`, `request()` or a `User`, and its audit actor is
 * `maintenance:AppKeyRotation` rather than `job:…`, so the trail says the system did this
 * with nobody asking. The ADR-003 D8 admin path in the PWA may *start* a rotation — the
 * Supervisor writes the envelope — but it may not call this pass inline; that would be a
 * user reaching every organization's rows through a class no policy is about.
 *
 * ## What this deliberately does not do
 *
 * It does not write the Keychain envelope, and it does not touch `app.key`. Writing the
 * new `current` and pushing the old one onto `previous[]` is the Supervisor's (ADR-003
 * D2: nothing but the Supervisor holds the access group), and the admin action that
 * starts a rotation invalidates sessions. This is the storage half only, which is why
 * it is safe to re-run: `reencrypt()` is idempotent, and running it when nothing has
 * rotated re-encrypts every row under the key it already uses.
 *
 * It also does not delete anything it could not read. A value no key in the envelope
 * can decrypt is counted and reported, never overwritten — see
 * `SensitiveAttributeRewriter::reencrypt()`. That is the only outcome that needs a
 * person: it means a key was lost rather than rotated, which **A25** is the decision
 * that prevents and `docs/operations/uninstall.md` §5 is the documentation of.
 */
final class AppKeyRotation implements TenantWideMaintenance
{
    /**
     * The lock that keeps two rotations from interleaving — the device ADR-003 D8
     * already uses to serialise token refreshes, and the one `CreateOwner` and the OTP
     * issuer use for the same reason. It is held for the whole pass rather than ten
     * seconds, because the pass is as long as the install's row count.
     */
    public const string LOCK = 'lea:app-key:reencrypt';

    public function __construct(
        private readonly SensitiveAttributeRewriter $rewriter,
        private readonly AuditLog $audit,
    ) {}

    /** The authorization.md §10 category's name for this operation, and the audit actor it produces. */
    public function maintenanceOperation(): string
    {
        return 'AppKeyRotation';
    }

    /**
     * Rewrites every inventoried attribute and the blind index under the current key,
     * and records what moved.
     *
     * @param  string  $reason  why the rotation ran, for the §10 audit row; never a key or a value
     * @return array<string, int> `<table>.<column>` => rows rewritten
     *
     * @throws LockTimeoutException another rotation is running
     */
    public function handle(string $reason = 'app_key_rotated'): array
    {
        return Cache::lock(self::LOCK, 3600)->block(5, fn (): array => $this->rewrite($reason));
    }

    /** @return array<string, int> */
    private function rewrite(string $reason): array
    {
        $rewritten = $this->rewriter->reencrypt();
        $hashes = $this->rewriter->rewriteContactEmailHashes(onlyMissing: false);
        $unreadable = array_filter($this->rewriter->unreadable());

        // Counts and column names only: §10's audit trail records that a rotation
        // happened and how much it moved, never a value (ADR-003 D7). The actor is the
        // #127 category's shape rather than `job:…`, because `job:` is also what a
        // pre-identity *user* action writes (`job:CreateOwnerForm`) and this one had no
        // user at all — see MaintenanceActor.
        $this->audit->record(
            action: 'secrets.app_key.reencrypted',
            actor: MaintenanceActor::for($this),
            outcome: $unreadable === [] ? AuditLog::OUTCOME_OK : AuditLog::OUTCOME_FAILED,
            subjectType: 'app_key',
            context: [
                'reason' => $reason,
                'attributes' => count(EncryptedAttributeInventory::qualifiedColumns()),
                'rows_reencrypted' => array_sum($rewritten),
                'blind_index_rows_rewritten' => $hashes,
                'unreadable' => $unreadable,
            ],
        );

        return $rewritten;
    }
}
