<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use App\Security\Audit\AuditLog;
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
 * ## Why this is not a `ShouldQueue` job yet — tracked as #127, not an oversight
 *
 * ADR-003 D8 says the re-encryption runs "in a queued job", and that is still the
 * intent. It is **not** implemented as one here because S2-06 (#88) landed a rule that
 * this operation cannot satisfy, and the collision needs a decision rather than a
 * workaround. `tests/Security/PolicySurfaceArchTest.php` requires every `ShouldQueue`
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
 * The honest conclusion is that re-encryption is not a user action at all. It belongs
 * to the same category as the S2-04 migration that first wrote these columns: a
 * storage-layer pass triggered by a key change, which is why that migration is not a
 * policy surface either. The **retention purge** (S2-03's `data_retention_rules`) and
 * the **backup job** (ADR-002 D5) will need exactly the same category, so naming it is
 * worth doing once and properly rather than inventing an exception here.
 *
 * **That is issue #127**, which names the category, gives it a marker whose members an
 * arch test enumerates — so growing the list costs a reviewed decision rather than an
 * import — requires a member to be unreachable from any user-facing surface, gives it
 * its own audit actor shape, and writes down what it may *not* do. S2-04 deliberately
 * does not invent any of that here: an unnamed exception is exactly how the next author
 * would justify the privileged job identity S2-06 exists to prevent.
 *
 * Until #127 lands this class is a plain action: the ADR-003 D8 admin path in the PWA
 * calls it, and `handle()` is what a job will call when there is a sanctioned way to be
 * one. It is a one-line change there and nothing here moves. Nothing about the behaviour or the tests changes when that happens —
 * `tests/Security/AppKeyRotationTest.php` already drives it directly. The
 * one-at-a-time guarantee that `ShouldBeUnique` would have given is kept by the cache
 * lock below, which is the same device ADR-003 D8 already uses to serialise token
 * refreshes.
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
final class AppKeyRotation
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
        // happened and how much it moved, never a value (ADR-003 D7).
        $this->audit->record(
            action: 'secrets.app_key.reencrypted',
            actor: 'job:AppKeyRotation',
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
