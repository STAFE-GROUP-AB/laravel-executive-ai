<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\Dto\AiToolDefinition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Stringable;

/**
 * One of our `AiToolDefinition`s offered to the model as an SDK tool.
 *
 * The important part is what it does **not** do. `laravel/ai`'s generation loop
 * executes a proposed tool and feeds the result back within the same run. That
 * is the opposite of §4 and §11, where the model only ever *proposes* and a
 * server-side `PolicyGate` decides. So every bridged tool is `Approvable` and
 * `shouldRequestApproval()` always returns `Approval::required()`: the loop then
 * stops at `pendingApprovals` and returns without calling anything
 * (`TextGenerationLoop::resolveToolCalls()` → `earlyStepToolResults()`), which
 * is how the adapter can hand its caller a *proposal* rather than a side effect.
 *
 * `handle()` is therefore unreachable, and throws rather than returning
 * something plausible: a tool body that quietly ran would be exactly the
 * failure this class exists to prevent, and it must be loud if the SDK ever
 * changes that path. ADR-004 D2 row 3 is the test.
 */
final class SdkToolBridge implements Approvable, Tool
{
    public function __construct(private readonly AiToolDefinition $definition) {}

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

    public function shouldRequestApproval(Request $request): Approval
    {
        return Approval::required('Every tool call is a proposal for the PolicyGate (§4, §11).');
    }

    public function handle(Request $request): Stringable|string
    {
        throw new LogicException(
            "Tool [{$this->definition->name}] was executed inside the model run; only the PolicyGate may execute a proposal (§4, §11, ADR-004 D2 row 3)."
        );
    }
}
