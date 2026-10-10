<?php

declare(strict_types=1);

namespace Tests\Security\Support;

/**
 * The seam between this expected-failing gate and the orchestrator that will make it
 * pass. S0-21 is written in Sprint 0, before `PolicyGate`, approvals and the agent
 * loop exist (issue #21: "it will fail because no PolicyGate exists yet — that is
 * the point"). The test therefore drives one small interface instead of a concrete
 * class, and E2-4/E2-5 plug the real thing in without the test being rewritten.
 *
 * ## What an implementation must do
 *
 * `process()` runs exactly one owner turn — `$arrival->ownerTurn()`, the only
 * `User`-role message — over the foreign email body in
 * `$arrival->untrustedContext()`, and returns what the run observably did. It must
 * use the collaborators the test has already bound into the container as instances,
 * so that every effect is recorded:
 *
 * | Container key | Bound to | Records |
 * |---|---|---|
 * | `App\AI\Providers\AiProviderAdapter` | `FakeAiProviderAdapter` | every `AiRequest`, and the scripted hostile proposals the model emits |
 * | `App\Integrations\Mail\EmailConnector` | `FakeEmailConnector` | any `send()` — the egress boundary this gate is about |
 * | `App\CRM\WorkspaceRepository` | `FakeWorkspaceRepository` | every read, so "refused before data was gathered" is checkable |
 * | `Tests\Security\Support\AuditLogSpy` | `InMemoryAuditLogSpy` | the refusal's audit event (§11, T-46) |
 *
 * Resolving collaborators any other way (a `new` in the orchestrator, a facade, a
 * direct HTTP client) makes the run unobservable and the gate meaningless, so the
 * test also asserts at the process boundary: `Http::preventStrayRequests()`,
 * `Mail::fake()` and `Queue::fake()` with zero sends.
 *
 * ## The binding point (E2-4 / E2-5)
 *
 * Nothing binds this interface today, so `app(AgentRunUnderTest::class)` throws and
 * the gate reports an expected failure naming exactly that. To turn it green:
 *
 * 1. add a test-side adapter (`tests/Security/Support/PolicyGatedAgentRun.php`, say)
 *    that implements `process()` by driving the real orchestrator through
 *    `PolicyGate` and `App\Approvals`;
 * 2. bind it for the Security suite — `app()->bind(AgentRunUnderTest::class, ...)`
 *    in the suite's `beforeEach`, or a test-only service provider;
 * 3. point `AuditLogSpy` at the real `audit_events` writer that S1-01 lands (see
 *    `AuditLogSpy` for that binding point);
 * 4. delete the `ExpectedFailure::guard()` wrapper. The helper fails the build on an
 *    unexpected pass precisely to force step 4 rather than leaving a lenient test.
 *
 * The interface lives under `tests/` on purpose: production code must not depend on
 * a shape that exists for one test, and S1-01 is concurrently building `app/` — the
 * gate must not race it.
 */
interface AgentRunUnderTest
{
    /**
     * Process one arriving foreign email on the frozen fixture clock
     * (`InjectionCorpus::FIXTURE_NOW`) and report the run's observable effects.
     *
     * An implementation must not throw for a refusal: refusing is the expected
     * behaviour, and it is reported through `AgentRunOutcome` (the refusal's
     * `user_message_key`, the audit event, the flagged brief item) so the test can
     * assert the refusal was *visible* and not merely silent.
     */
    public function process(ForeignEmailArrival $arrival): AgentRunOutcome;
}
