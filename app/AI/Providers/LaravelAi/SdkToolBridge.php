<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\Dto\AiToolDefinition;
use App\Approvals\Policies\PolicyGate;
use App\Approvals\Policies\PolicyReason;
use App\Approvals\Policies\PolicyVerdict;
use App\Approvals\Policies\ProposedAction;
use App\Approvals\Policies\Rules\RuleBook;
use App\Approvals\Policies\ToolCallContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;

/**
 * One of our `AiToolDefinition`s offered to the model as an SDK tool.
 *
 * The important part is what it does **not** do. `laravel/ai`'s generation loop
 * executes a proposed tool and feeds the result back within the same run. That is
 * the opposite of §4 and §11, where the model only ever *proposes* and a
 * server-side `PolicyGate` decides. ADR-004 D2's tools row puts the gate inside
 * `handle()`, before the wrapped tool runs, and contract matrix row 3 requires that
 * a denial produce a tool result the model can read with nothing executed.
 *
 * ## What S2-07 changed, and why it is not a loosening
 *
 * S2-13 made `shouldRequestApproval()` return `Approval::required()` for every tool
 * unconditionally and `handle()` throw, deliberately stricter than ADR-004 sketched
 * because there was no gate yet to decide. There is one now, so the decision moves
 * from a blanket to a **rule a human wrote** — and every path stays closed:
 *
 *  - **No rule → approval required.** `PolicyGate::requiresApproval()` answers true
 *    for any tool the `RuleBook` does not claim, so an unknown tool stops the loop
 *    at `pendingApprovals` exactly as before. Fail-closed is the default, not the
 *    exception.
 *  - **A mutating rule → approval required.** `email.send`, `crm.delete`,
 *    `automation.execute`: identical behaviour to S2-13.
 *  - **A read-only rule → the loop reaches `handle()`**, which puts the proposal to
 *    the gate *first* and returns the verdict. Only `ReadOnlyQueryRule` is in that
 *    class, and only because a human put it there.
 *  - **`handle()` still executes nothing, on either branch.** An
 *    `AiToolDefinition` carries no executor — that is stated in the DTO itself —
 *    so there is no wrapped body for this class to call, and the result always says
 *    `executed=none`. An allowed call comes back as the ADR-005 D4.10 shape: the
 *    proposal exists, the model is told so, and acting on it is the approvals
 *    engine's job in a different process.
 *  - **`withoutApproval()` still throws.** The one method that could make a tool
 *    auto-executable refuses to exist.
 *
 * `shouldRequestApproval()` reads the tool **name** and the `RuleBook`. It does not
 * read the request's arguments, the tool's description, the model's text or any
 * prompt — ADR-005 D4.8, and `tests/Security/PolicyIsNotPromptTest.php` fails if
 * that changes.
 *
 * ## Why the gate and the context are optional
 *
 * Sprint 2 ships no tool and no orchestrator that knows which organization, account
 * and trace a tool call belongs to; S2-15/E2-4 build that. A bridge constructed
 * without a `ToolCallContext` therefore cannot identify a scope — and answers that
 * the way everything else here answers an unknown: `handle()` refuses with
 * `PolicyReason::ScopeViolation` and nothing runs. The absent collaborator is a
 * refusal, never a bypass.
 */
final class SdkToolBridge implements Approvable, Tool
{
    public function __construct(
        private readonly AiToolDefinition $definition,
        private readonly ?ToolCallContext $context = null,
        private readonly ?PolicyGate $gate = null,
    ) {}

    public function name(): string
    {
        return $this->definition->name;
    }

    public function description(): string
    {
        return $this->definition->description;
    }

    /**
     * The framework's JSON-schema types, pass-through: `AiToolDefinition
     * ::$parameters` is already `array<string, Illuminate\JsonSchema\Types\Type>`,
     * the exact shape `Tool::schema()` must return, and per-property
     * `->required()` is materialised by the serializer into the parent object's
     * `required` list rather than on the property itself — so nothing here may
     * pre-serialise it.
     *
     * The model's schema and the gate's schema are not the same object and must not
     * be: this one tells the model what to send, `Rules\ArgumentSchema` decides what
     * is acceptable. A gate that validated against the schema it advertised would be
     * checking the model's homework against the model's own answer key.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->definition->parameters;
    }

    public function requireApproval(?string $reason = null): static
    {
        return $this;
    }

    /**
     * Refusing to be made auto-executable is deliberate: this would otherwise be
     * one method call away from letting the model act without the gate.
     */
    public function withoutApproval(): static
    {
        throw new LogicException('A bridged tool may never run without the PolicyGate (§4, ADR-004 D2).');
    }

    /**
     * Whether this tool is approval-driven, decided from its rule and from nothing
     * in `$request`. `Approval::required()` stops the loop at `pendingApprovals`
     * having run nothing (`TextGenerationLoop::resolveToolCalls()` →
     * `earlyStepToolResults()`), which is how the adapter hands its caller a
     * *proposal* rather than a side effect.
     *
     * Null — "no approval needed" — is reachable only for a tool a rule in
     * `Rules/` declares read-only, and the gate still decides in `handle()`.
     */
    public function shouldRequestApproval(Request $request): ?Approval
    {
        $rule = $this->rules()->for($this->definition->name);

        if ($rule === null || $rule->requiresApproval()) {
            return Approval::required('Every mutating tool call is a proposal for the PolicyGate (§4, §11).');
        }

        return null;
    }

    /**
     * The gate, before anything else, and nothing else after it.
     *
     * The first statement reaches `PolicyGate::decide()`; the last returns the
     * verdict as a model-readable tool result. There is no branch between them on
     * which a tool body could run, which is matrix row 3 made structural rather
     * than asserted: `tests/Security/PolicyIsNotPromptTest.php` reads this method's
     * source and fails if a call appears ahead of the gate.
     */
    public function handle(Request $request): string
    {
        return $this->decide($request->toArray())->toToolResult();
    }

    /**
     * @param  array<string, mixed>  $arguments  the arguments the model proposed
     */
    private function decide(array $arguments): PolicyVerdict
    {
        $context = $this->context;

        if ($context === null || $this->gate === null) {
            // No scope, so no decision can be made about one. Recorded nowhere
            // because there is no organization to record it against — and refused
            // for exactly that reason.
            return PolicyVerdict::refuse(
                RuleBook::NO_RULE,
                PolicyReason::ScopeViolation,
                ProposedAction::freeze($this->definition->name, '', $arguments),
            );
        }

        // A bridge can only ever propose a call with **no destination**: it has the
        // arguments and the run's scope, and nothing that resolves a recipient or a
        // target app. That is why a mutating tool refused here stays refused even
        // when an approval exists — executing an approved proposal needs the frozen
        // row, which `App\Approvals` reads and the SDK loop does not have. Only a
        // read-only rule can pass, and `DestinationRule::none()` is what makes that
        // a check rather than a coincidence.
        return $this->gate->decide(
            ProposedAction::freeze(
                toolName: $this->definition->name,
                organizationId: $context->organizationId,
                payload: $arguments,
                destinations: [],
                dataClass: $context->dataClass,
                accountId: $context->accountId,
            ),
            $context,
        );
    }

    private function rules(): RuleBook
    {
        return $this->gate?->rules() ?? new RuleBook;
    }
}
