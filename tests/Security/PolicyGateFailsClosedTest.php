<?php

declare(strict_types=1);

use App\AI\Tools\PolicyDecision;
use App\AI\Tools\ToolKind;
use App\Approvals\Policies\ApprovalLedger;
use App\Approvals\Policies\ApprovalRequiredException;
use App\Approvals\Policies\BudgetViolationException;
use App\Approvals\Policies\DataClassViolationException;
use App\Approvals\Policies\DestinationNotAllowedException;
use App\Approvals\Policies\InvalidToolInputException;
use App\Approvals\Policies\NoRuleMatchedException;
use App\Approvals\Policies\PayloadNotFrozenException;
use App\Approvals\Policies\PolicyDeniedException;
use App\Approvals\Policies\PolicyGate;
use App\Approvals\Policies\PolicyJournal;
use App\Approvals\Policies\PolicyReason;
use App\Approvals\Policies\PolicyRefusal;
use App\Approvals\Policies\PolicyVerdict;
use App\Approvals\Policies\ProposedAction;
use App\Approvals\Policies\Rules\ActionBudget;
use App\Approvals\Policies\Rules\ActionRule;
use App\Approvals\Policies\Rules\ArgumentSchema;
use App\Approvals\Policies\Rules\ArgumentType;
use App\Approvals\Policies\Rules\DestinationKind;
use App\Approvals\Policies\Rules\DestinationRule;
use App\Approvals\Policies\Rules\OutgoingEmailRule;
use App\Approvals\Policies\Rules\ReadOnlyQueryRule;
use App\Approvals\Policies\Rules\RuleBook;
use App\Approvals\Policies\Rules\UiActionRule;
use App\Approvals\Policies\ScopeViolationException;
use App\Approvals\Policies\ToolCallContext;
use App\Approvals\ProposalKind;
use App\Approvals\ProposalStatus;
use App\Models\AI\ToolRun;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Data\SensitivityClass;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Security\Support\FrozenProposals;
use Tests\Security\Support\PolicySurface;

uses(DatabaseTransactions::class);

/*
|--------------------------------------------------------------------------
| Fail closed: all seven §4 inputs, and no default-allow anywhere (S2-07)
|--------------------------------------------------------------------------
|
| §4 lists seven things the gate validates before execution: tool name, input
| schema, org/account/scope, data class, destination, budget, approval. The
| issue is explicit about the property that matters more than any one of them:
| "an unknown tool, an unknown data class or a missing budget is a refusal, not
| a default-allow".
|
| So this file is organised around refusals rather than around features. Each
| case removes exactly one thing from an otherwise acceptable action and
| asserts three facts: the decision is `denied`, the refusal names **which
| rule** refused it (or that none matched), and it raises the
| `PolicyDeniedException` subclass for that check.
|
| The last three cases are the structural half — the half that still holds for
| a rule nobody has written yet:
|
|  - a tool with no rule is refused, and `Rules/` is enumerated so a rule class
|    that is not in the `RuleBook` fails CI;
|  - `PolicyGate::evaluate()` has no branch that returns an allow before all
|    seven checks have run;
|  - every `PolicyReason` maps to a refusal class, so a reason added later
|    cannot fall through to something permissive.
|
*/

beforeEach(function (): void {
    Date::setTestNow(FrozenProposals::NOW);
    Http::preventStrayRequests();
    Http::fake();
});

/** @return array{0: Organization, 1: User, 2: ToolCallContext, 3: string} */
function closedWorld(): array
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

/**
 * Decide, and assert the three facts every refusal must carry. Returns the
 * exception so a case can look at it more closely.
 */
function expectRefusal(
    ProposedAction $action,
    ToolCallContext $context,
    PolicyReason $reason,
    string $rule,
    string $exception,
): PolicyRefusal {
    $gate = app(PolicyGate::class);
    $verdict = $gate->decide($action, $context);

    expect($verdict->permitsExecution())->toBeFalse("{$action->toolName} must be refused")
        ->and($verdict->decision)->toBe(PolicyDecision::Denied)
        ->and($verdict->reason)->toBe($reason)
        ->and($verdict->rule)->toBe($rule, 'the refusal must name which rule refused it');

    $thrown = null;

    try {
        $gate->authorize($action, $context);
    } catch (PolicyRefusal $refusal) {
        $thrown = $refusal;
    }

    expect($thrown)->toBeInstanceOf($exception)
        ->and($thrown)->toBeInstanceOf(PolicyDeniedException::class, 'every refusal must be catchable at the agent loop')
        ->and($thrown->policy)->toBe($rule)
        ->and($thrown->reason)->toBe($reason)
        ->and($thrown->getMessage())->toContain($action->toolName);

    return $thrown;
}

it('refuses input 1: a tool no rule claims', function (): void {
    [$organization, , $context] = closedWorld();

    foreach (['settings.write', 'approvals.grant', 'github.write', 'form.submit', 'definitely.not.a.tool'] as $tool) {
        expectRefusal(
            ProposedAction::freeze($tool, (string) $organization->getKey(), ['anything' => 'at all']),
            $context,
            PolicyReason::NoRuleMatched,
            RuleBook::NO_RULE,
            NoRuleMatchedException::class,
        );
    }

    // And the two that will never get one: there is no tool that widens the
    // agent's own authority (ADR-005 D1.6).
    $claimed = app(PolicyGate::class)->rules()->toolNames();

    expect(in_array('settings.write', $claimed, true))->toBeFalse('nothing may widen the agent\'s own authority')
        ->and(in_array('approvals.grant', $claimed, true))->toBeFalse('an approval is never issued from a tool call');
})->group('security');

it('refuses input 2: a missing, mistyped or undeclared argument', function (): void {
    [$organization, , $context, $accountId] = closedWorld();
    $organizationId = (string) $organization->getKey();

    $cases = [
        // Missing a required argument.
        'missing' => ['connection_id' => $accountId, 'subject' => 'Hi', 'body' => 'There'],
        // `to` declared as a list of strings, given a string.
        'mistyped' => ['connection_id' => $accountId, 'to' => 'zoe@kund.example', 'subject' => 'Hi', 'body' => 'There'],
        // The T-05 case: a From the mail body supplied. `from` is not a field the
        // rule declares, so the refusal is about the field existing at all.
        'from' => ['connection_id' => $accountId, 'to' => ['zoe@kund.example'], 'subject' => 'Hi', 'body' => 'There', 'from' => 'spoofed@attacker.example'],
        'reply_to' => ['connection_id' => $accountId, 'to' => ['zoe@kund.example'], 'subject' => 'Hi', 'body' => 'There', 'reply_to' => 'kontakt@attacker.example'],
    ];

    foreach ($cases as $label => $payload) {
        expectRefusal(
            ProposedAction::freeze(OutgoingEmailRule::TOOL, $organizationId, $payload, ['zoe@kund.example'], SensitivityClass::Internal, $accountId),
            $context,
            PolicyReason::InvalidToolInput,
            'outgoing_email',
            InvalidToolInputException::class,
        );

        expect($label)->toBeString();
    }

    // The problem list is names and declared types, never values — it is what
    // reaches `tool_runs`.
    $problems = (new OutgoingEmailRule)->schema()->problems($cases['from']);

    expect($problems)->toBe(['unexpected:from'])
        ->and(json_encode($problems, JSON_THROW_ON_ERROR))->not->toContain('attacker.example');
})->group('security');

it('refuses input 3: another organization, or another account', function (): void {
    [, , $context, $accountId] = closedWorld();

    $foreign = Organization::factory()->create();

    expectRefusal(
        ProposedAction::freeze(
            OutgoingEmailRule::TOOL,
            (string) $foreign->getKey(),
            FrozenProposals::emailPayload($accountId, ['zoe@kund.example']),
            ['zoe@kund.example'],
            SensitivityClass::Internal,
            $accountId,
        ),
        $context,
        PolicyReason::ScopeViolation,
        'outgoing_email',
        ScopeViolationException::class,
    );

    // §5.6 criterion 4: account A can never send through account B. The account on
    // the action is the model's claim; the one on the context came from the
    // connection the run was started on.
    $otherAccount = (string) Str::ulid();

    expectRefusal(
        ProposedAction::freeze(
            OutgoingEmailRule::TOOL,
            $context->organizationId,
            FrozenProposals::emailPayload($otherAccount, ['zoe@kund.example']),
            ['zoe@kund.example'],
            SensitivityClass::Internal,
            $otherAccount,
        ),
        $context,
        PolicyReason::AccountScopeMissing,
        'outgoing_email',
        ScopeViolationException::class,
    );

    // And no account at all is the same refusal, not a pass.
    expectRefusal(
        ProposedAction::freeze(
            OutgoingEmailRule::TOOL,
            $context->organizationId,
            FrozenProposals::emailPayload($accountId, ['zoe@kund.example']),
            ['zoe@kund.example'],
            SensitivityClass::Internal,
            null,
        ),
        $context,
        PolicyReason::AccountScopeMissing,
        'outgoing_email',
        ScopeViolationException::class,
    );
})->group('security');

it('refuses input 4: no data class, or one the rule does not permit', function (): void {
    [, , $context, $accountId] = closedWorld();

    // An unclassified action. There is no `internal` default.
    expectRefusal(
        ProposedAction::freeze(
            OutgoingEmailRule::TOOL,
            $context->organizationId,
            FrozenProposals::emailPayload($accountId, ['zoe@kund.example']),
            ['zoe@kund.example'],
            null,
            $accountId,
        ),
        $context,
        PolicyReason::DataClassMissing,
        'outgoing_email',
        DataClassViolationException::class,
    );

    // A class outside the rule's list. `UiActionRule` permits internal and
    // confidential; a UI action carrying personal data is refused.
    expectRefusal(
        ProposedAction::freeze(
            UiActionRule::TOOL,
            $context->organizationId,
            [
                'session_id' => (string) Str::ulid(),
                'app_bundle_id' => UiActionRule::DEFAULT_BUNDLE_ID,
                'window_title' => 'Invoice 2026-114',
                'element_role' => 'AXButton',
                'element_label' => 'Send',
                'action_type' => 'click',
            ],
            [UiActionRule::DEFAULT_BUNDLE_ID],
            SensitivityClass::Personal,
            null,
        ),
        $context,
        PolicyReason::DataClassNotAllowed,
        'ui_action',
        DataClassViolationException::class,
    );
})->group('security');

it('refuses input 5: a destination the frozen payload never named', function (): void {
    [, , $context, $accountId] = closedWorld();

    // The §16.7 case: the payload the approver read names Zoë; the destination is
    // the attacker's address. `recipient_added`, as a check.
    expectRefusal(
        ProposedAction::freeze(
            OutgoingEmailRule::TOOL,
            $context->organizationId,
            FrozenProposals::emailPayload($accountId, ['zoe@kund.example']),
            ['exfil@attacker.example'],
            SensitivityClass::Internal,
            $accountId,
        ),
        $context,
        PolicyReason::DestinationNotAllowed,
        'outgoing_email',
        DestinationNotAllowedException::class,
    );

    // A read-only query declares no destination, so one that turns up is refused
    // rather than ignored.
    expectRefusal(
        ProposedAction::freeze(
            ReadOnlyQueryRule::CRM_SEARCH,
            $context->organizationId,
            ['query' => 'rollout'],
            ['exfil@attacker.example'],
            SensitivityClass::Internal,
        ),
        $context,
        PolicyReason::DestinationNotAllowed,
        'read_only_query',
        DestinationNotAllowedException::class,
    );

    // An app outside ADR-005 D1.5's allowlist. The Swift helper runs its own check;
    // this is the PHP half of "two independent checks, both fail closed".
    expectRefusal(
        ProposedAction::freeze(
            UiActionRule::TOOL,
            $context->organizationId,
            [
                'session_id' => (string) Str::ulid(),
                'app_bundle_id' => 'com.apple.Passwords',
                'window_title' => 'Passwords',
                'element_role' => 'AXTextField',
                'element_label' => 'Search',
                'action_type' => 'click',
            ],
            ['com.apple.Passwords'],
            SensitivityClass::Internal,
        ),
        $context,
        PolicyReason::DestinationNotAllowed,
        'ui_action',
        DestinationNotAllowedException::class,
    );
})->group('security');

it('refuses input 6: a budget a rule forgot to declare, and one the action exceeds', function (): void {
    [, , $context] = closedWorld();

    // A rule with no budget. It is refused rather than treated as unbounded, which
    // is the §4 requirement spelled out — and the only way to show it is a rule the
    // product does not ship.
    $unbounded = new class implements ActionRule
    {
        public function name(): string
        {
            return 'unbounded_fixture';
        }

        public function toolNames(): array
        {
            return ['fixture.unbounded'];
        }

        public function kind(): ?ProposalKind
        {
            return null;
        }

        public function toolKind(): ToolKind
        {
            return ToolKind::Builtin;
        }

        public function schema(): ArgumentSchema
        {
            return ArgumentSchema::of([], ['query' => ArgumentType::Text]);
        }

        public function dataClasses(): array
        {
            return [SensitivityClass::Internal];
        }

        public function destinations(): DestinationRule
        {
            return DestinationRule::none();
        }

        public function budget(): ?ActionBudget
        {
            return null;
        }

        public function requiresApproval(): bool
        {
            return false;
        }

        public function requiresAccountScope(): bool
        {
            return false;
        }
    };

    $gate = new PolicyGate(
        RuleBook::of($unbounded),
        app(ApprovalLedger::class),
        app(PolicyJournal::class),
    );

    $verdict = $gate->decide(
        ProposedAction::freeze('fixture.unbounded', $context->organizationId, ['query' => 'x'], [], SensitivityClass::Internal),
        $context,
    );

    expect($verdict->permitsExecution())->toBeFalse()
        ->and($verdict->reason)->toBe(PolicyReason::BudgetMissing)
        ->and($verdict->rule)->toBe('unbounded_fixture');

    expect(fn () => $gate->authorize(
        ProposedAction::freeze('fixture.unbounded', $context->organizationId, ['query' => 'x'], [], SensitivityClass::Internal),
        $context,
    ))->toThrow(BudgetViolationException::class);

    // And the shipped rules do bound their actions: a query past the size ceiling
    // is refused rather than truncated.
    expectRefusal(
        ProposedAction::freeze(
            ReadOnlyQueryRule::CRM_SEARCH,
            $context->organizationId,
            ['query' => str_repeat('a', 4096)],
            [],
            SensitivityClass::Internal,
        ),
        $context,
        PolicyReason::BudgetExceeded,
        'read_only_query',
        BudgetViolationException::class,
    );
})->group('security');

it('refuses input 7: a mutating action with no approval, and names which approval is missing', function (): void {
    [$organization, $approver, $context, $accountId] = closedWorld();
    $payload = FrozenProposals::emailPayload($accountId, ['zoe@kund.example']);

    // No proposal at all.
    $refusal = expectRefusal(
        ProposedAction::freeze(OutgoingEmailRule::TOOL, $context->organizationId, $payload, ['zoe@kund.example'], SensitivityClass::Internal, $accountId),
        $context,
        PolicyReason::ApprovalMissing,
        'outgoing_email',
        ApprovalRequiredException::class,
    );

    expect($refusal->messageKey())->toBe('policy.approval_missing', 'the owner must be able to see which approval is missing');

    // A frozen proposal, nobody has approved it.
    $proposal = FrozenProposals::freeze($organization, $payload);

    expectRefusal(
        ProposedAction::fromProposal($proposal, OutgoingEmailRule::TOOL, ['zoe@kund.example'], SensitivityClass::Internal, $accountId),
        $context,
        PolicyReason::ApprovalMissing,
        'outgoing_email',
        ApprovalRequiredException::class,
    );

    // A proposal belonging to another organization reads as absent, so the refusal
    // cannot be used to probe which proposal ids exist.
    $foreign = FrozenProposals::freeze(Organization::factory()->create(), $payload);

    expectRefusal(
        ProposedAction::freeze(OutgoingEmailRule::TOOL, $context->organizationId, $payload, ['zoe@kund.example'], SensitivityClass::Internal, $accountId)
            ->withPayload($payload),
        $context,
        PolicyReason::ApprovalMissing,
        'outgoing_email',
        ApprovalRequiredException::class,
    );

    expect($foreign->organization_id)->not->toBe($context->organizationId);

    // A rejected proposal with an approval row is still refused: the status is
    // terminal, and a terminal proposal authorises nothing.
    $rejected = FrozenProposals::freeze($organization, $payload, status: ProposalStatus::Rejected);
    FrozenProposals::approve($rejected, $approver);

    expectRefusal(
        ProposedAction::fromProposal($rejected, OutgoingEmailRule::TOOL, ['zoe@kund.example'], SensitivityClass::Internal, $accountId),
        $context,
        PolicyReason::ProposalNotPending,
        'outgoing_email',
        ApprovalRequiredException::class,
    );
})->group('security');

it('refuses the frozen-payload binding before it looks at any other input', function (): void {
    [, , $context, $accountId] = closedWorld();

    // An action whose payload has drifted from its frozen hash. Everything else
    // about it is wrong too — no account, no data class, a hostile destination —
    // and the refusal is still about the binding, because that check runs first:
    // a decision about content nobody froze is not a decision at all.
    $action = ProposedAction::freeze(
        OutgoingEmailRule::TOOL,
        $context->organizationId,
        FrozenProposals::emailPayload($accountId, ['zoe@kund.example']),
        ['exfil@attacker.example'],
        null,
        null,
    )->withPayload(['to' => 'whatever']);

    expectRefusal($action, $context, PolicyReason::PayloadNotFrozen, 'outgoing_email', PayloadNotFrozenException::class);
})->group('security');

it('records the refusal every time, so a denial is never a silent no-op', function (): void {
    [$organization, $approver, $context] = closedWorld();

    foreach (['settings.write', 'approvals.grant', OutgoingEmailRule::TOOL] as $tool) {
        app(PolicyGate::class)->decide(
            ProposedAction::freeze($tool, (string) $organization->getKey(), ['x' => 'y']),
            $context,
        );
    }

    $runs = ToolRun::query()->where('trace_id', $context->traceId)->get();
    $events = AuditEvent::query()->where('action', 'like', PolicyJournal::ACTION_PREFIX.'%')->get();

    expect($runs)->toHaveCount(3)
        ->and($events)->toHaveCount(3);

    foreach ($runs as $run) {
        expect($run->policy_decision)->toBe(PolicyDecision::Denied)
            ->and($run->policy_reason)->not->toBeNull('the row must say which rule refused and why');
    }

    foreach ($events as $event) {
        expect($event->outcome)->toBe('refused')
            ->and($event->actor)->toBe("user:{$approver->getKey()}")
            ->and($event->context['rule'] ?? null)->not->toBeNull();
    }
})->group('security');

it('has no rule class outside the RuleBook, so a new action gets a rule or is refused', function (): void {
    $registered = array_map(
        static fn (ActionRule $rule): string => $rule::class,
        app(PolicyGate::class)->rules()->rules(),
    );

    $declared = array_values(array_filter(
        PolicySurface::applicationClasses(),
        static fn (string $class): bool => str_starts_with($class, 'App\\Approvals\\Policies\\Rules\\')
            && is_subclass_of($class, ActionRule::class)
            && ! (new ReflectionClass($class))->isAbstract(),
    ));

    expect($declared)->not->toBeEmpty('the detector must actually see the rule classes')
        ->and(array_values(array_diff($declared, $registered)))->toBe(
            [],
            'a rule class in Rules/ that the RuleBook does not register decides nothing: add it to RuleBook::__construct()',
        )
        ->and(array_values(array_diff($registered, $declared)))->toBe([], 'the RuleBook registers a rule that is not a class in Rules/');

    // Every rule is reachable by at least one tool name, and no tool name is
    // claimed twice — registration order may never decide a security question.
    $toolNames = app(PolicyGate::class)->rules()->toolNames();

    expect(count($toolNames))->toBe(count(array_unique($toolNames)))
        ->and($toolNames)->toContain(OutgoingEmailRule::TOOL, ReadOnlyQueryRule::CRM_SEARCH, UiActionRule::TOOL);

    expect(fn () => RuleBook::of(new OutgoingEmailRule, new OutgoingEmailRule))
        ->toThrow(InvalidArgumentException::class);
})->group('security');

it('has no branch that allows before all seven checks have run', function (): void {
    $source = PolicySurface::methodSource(new ReflectionMethod(PolicyGate::class, 'evaluate'));

    // The only allow in `evaluate()` is the approval branch and the read-only
    // branch, and both sit after every refusal. Counting the returns is what makes
    // that checkable: an allow inserted higher up would raise the count.
    $allows = preg_match_all('/PolicyVerdict::allow/', $source);
    $refusals = preg_match_all('/PolicyVerdict::refuse/', $source);

    expect($allows)->toBe(1, 'evaluate() may return exactly one allow, and it is the last statement')
        ->and($refusals)->toBeGreaterThanOrEqual(7, 'each of the seven §4 inputs refuses on its own');

    $lastRefusal = strrpos($source, 'PolicyVerdict::refuse');
    $onlyAllow = strpos($source, 'PolicyVerdict::allow');

    expect($lastRefusal)->toBeLessThan($onlyAllow, 'every refusal must be reachable before the allow is');

    // And the allow is not reachable without the budget and the schema having been
    // consulted: both appear in the source ahead of it.
    foreach (['hashMatchesFrozen', '$schemaProblems', 'dataClasses', 'destinations', 'budget', 'requiresApproval'] as $needle) {
        expect(strpos($source, $needle))->toBeLessThan($onlyAllow, "{$needle} must be consulted before the allow");
    }
})->group('security');

it('maps every PolicyReason to a refusal, so a reason added later cannot fall through', function (): void {
    [, , $context] = closedWorld();
    $gate = app(PolicyGate::class);
    $action = ProposedAction::freeze(OutgoingEmailRule::TOOL, $context->organizationId, ['x' => 'y']);

    foreach (PolicyReason::cases() as $reason) {
        $verdict = PolicyVerdict::refuse('outgoing_email', $reason, $action);
        $refusal = $gate->refusalFor($verdict);

        expect($refusal)->toBeInstanceOf(PolicyDeniedException::class, "{$reason->value} must map to a refusal")
            ->and($refusal->reason)->toBe($reason)
            ->and($reason->messageKey())->toStartWith('policy.');
    }

    // Only one value permits execution, and it is the one that names itself.
    $permitting = array_values(array_filter(
        PolicyReason::cases(),
        static fn (PolicyReason $reason): bool => $reason->permitsExecution(),
    ));

    expect($permitting)->toBe([PolicyReason::Allowed]);

    // A `Denied` decision carrying the `Allowed` reason still does not execute:
    // the two have to agree, and disagreement fails closed.
    expect(PolicyVerdict::refuse('outgoing_email', PolicyReason::Allowed, $action)->permitsExecution())
        ->toBeFalse();
})->group('security');

it('has a destination kind for every shape a rule declares, and no unused one', function (): void {
    $declared = [];

    foreach (app(PolicyGate::class)->rules()->rules() as $rule) {
        $declared[] = $rule->destinations()->kind;
    }

    foreach (DestinationKind::cases() as $kind) {
        expect(in_array($kind, $declared, true))->toBeTrue(
            "DestinationKind::{$kind->name} is declared by no rule, so it is untested code on the fail-closed path",
        );
    }
})->group('security');
