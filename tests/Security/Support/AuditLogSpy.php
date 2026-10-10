<?php

declare(strict_types=1);

namespace Tests\Security\Support;

/**
 * The read seam for `audit_event_recorded` (§11; threat model T-46: the audit row is
 * the only forensic artefact of a refusal, because the payload itself is never
 * copied into it).
 *
 * S0-21 cannot assert against the `audit_events` table: S1-01 is building the
 * migration, the model and `app/Security/Logging/` concurrently on another branch,
 * and this gate must not create any of them. So the assertion goes through this
 * interface, and the real writer is plugged in later:
 *
 * ## Binding point (S1-01 → E2-4)
 *
 * - today: `app()->instance(AuditLogSpy::class, new InMemoryAuditLogSpy)`, and the
 *   implementation of `AgentRunUnderTest` writes its refusal events through it;
 * - once S1-01 has landed `audit_events` and its writer, replace
 *   `InMemoryAuditLogSpy` with a thin adapter that implements `events()` by reading
 *   the `audit_events` rows written during the run (and `record()` by delegating to
 *   the real writer). Nothing in the test or in `Refusal` changes.
 *
 * ## The event type is the frozen refusal key
 *
 * The corpus already carries a stable identifier for each refusal:
 * `expected.user_message_key` (`security.injection.exfiltration_attempt`, ...). The
 * suite asserts the audit event's `type` equals that key, so the UI message and the
 * forensic record cannot drift apart, and no new vocabulary has to be invented
 * before E2-4 exists.
 *
 * `context` carries references (source id, account id, policy decision) — never the
 * payload text. `Refusal` asserts that too: an audit trail that quotes the attack
 * verbatim turns the forensic record into a second copy of the hostile instruction.
 */
interface AuditLogSpy
{
    /**
     * @param  array<string, mixed>  $context  references only; never payload text
     */
    public function record(string $type, array $context = []): void;

    /**
     * @return list<array{type: string, context: array<string, mixed>}>
     */
    public function events(): array;

    /**
     * @return list<string>
     */
    public function types(): array;
}
