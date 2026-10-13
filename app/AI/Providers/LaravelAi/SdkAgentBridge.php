<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\Dto\AiToolDefinition;
use App\Approvals\Policies\PolicyGate;
use App\Approvals\Policies\ToolCallContext;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * An SDK agent built from one `AiRequest` (ADR-004 D2). It is the object
 * `Promptable::prompt()` and `::stream()` are called on, and it exists per
 * request rather than per provider because everything the SDK takes from an
 * agent — instructions, tools, bounds — is per request for us.
 *
 * Bounds are exposed as methods, not attributes: `TextGenerationOptions
 * ::forAgent()` prefers `maxSteps()`, `maxTokens()`, `temperature()` and
 * `topP()` over the class attributes, which is the only way a dynamically built
 * agent can carry them. `timeout()` is deliberately **not** defined — the
 * timeout travels as `prompt()`'s own argument, and defining the method would
 * force an `int` where the request legitimately has none.
 *
 * Deliberately **not** `Conversational` and deliberately not using
 * `RemembersConversations`: conversation and memory are app-owned (§4), the
 * SDK's `agent_conversations` table is never published (D1), and
 * `withMessages()` refuses to run on a conversational agent anyway.
 */
class SdkAgentBridge implements Agent, HasTools
{
    use Promptable;

    /**
     * `$toolContext` and `$gate` are what each `SdkToolBridge` decides inside
     * (S2-07). They are optional because Sprint 2 ships no orchestrator that knows
     * the organization, account and trace a tool call belongs to — and a bridge
     * built without them refuses every tool call rather than running one unchecked,
     * so the absence is a closed door and not an open one.
     */
    public function __construct(
        protected readonly AiRequest $request,
        protected readonly string $instructions,
        protected readonly ?ToolCallContext $toolContext = null,
        protected readonly ?PolicyGate $gate = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->instructions;
    }

    /**
     * Every tool is bridged as an approval-gated proposal; see `SdkToolBridge`.
     *
     * @return iterable<int, SdkToolBridge>
     */
    public function tools(): iterable
    {
        return array_map(
            fn (AiToolDefinition $definition): SdkToolBridge => new SdkToolBridge(
                $definition,
                $this->toolContext,
                $this->gate,
            ),
            $this->request->tools,
        );
    }

    /**
     * The step budget. ADR-004 D7 caps it at the SDK's own
     * `DEFAULT_MAX_STEPS = 25`, which we never exceed; the supervisor (S2-15)
     * counts steps itself as well, so a driver that ignored this still cannot
     * overrun.
     */
    public function maxSteps(): ?int
    {
        return $this->request->maxSteps === null ? null : min($this->request->maxSteps, 25);
    }

    public function maxTokens(): ?int
    {
        return $this->request->maxOutputTokens;
    }

    public function temperature(): ?float
    {
        return $this->request->temperature;
    }

    public function topP(): ?float
    {
        return $this->request->topP;
    }
}
