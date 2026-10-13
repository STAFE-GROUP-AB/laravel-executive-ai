<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Runs the named ability for one tool call, as the dispatching user, on the
 * `tool` surface (threat model T-73's fifth path).
 *
 * It composes with the S2-13 refusals rather than duplicating them: the
 * `ModelRouter` already refuses a run whose privacy mode forbids the only
 * capable model, and `ProviderRegistry` already refuses an unconfigured
 * provider. Those answer "may this model be used at all"; this answers "may
 * this user touch this row" — both must pass, and neither is a substitute for
 * the other.
 *
 * The ability must be one the Gate or a policy can answer. A tool that names
 * one nothing answers is refused with its own exception rather than with a
 * bare 403, because that is a defect in the tool and not a decision about the
 * user — the refusal is on the audit record either way.
 */
final class ToolAuthorizer
{
    public function __construct(
        private readonly Gate $gate,
        private readonly SurfaceContext $surfaces,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  the arguments the model proposed
     *
     * @throws AuthorizationException the user may not touch what the tool asked for
     */
    public function authorize(User $user, GatedTool $tool, array $arguments = []): void
    {
        $this->surfaces->within(Surface::Tool, function () use ($user, $tool, $arguments): void {
            $ability = $tool->ability();
            $subject = $tool->subject($arguments);

            // `raw()` rather than `authorize()`: it runs the check once, so a
            // refusal leaves one audit row rather than two, and it tells
            // "nothing answered" (null) apart from "answered no" (false).
            $decision = $this->gate->forUser($user)->raw($ability, $subject === null ? [] : [$subject]);

            if ($decision === null) {
                // Already on the record: the `after` hook saw the null and
                // wrote it as refused with `reason: unanswered`. The throw is
                // here so a tool naming a nonexistent ability fails loudly
                // instead of being a deny the author reads as a bug.
                throw new AuthorizationException("The tool {$tool->name()} names an ability nothing answers: {$ability}.");
            }

            ($decision instanceof Response ? $decision : ($decision ? Response::allow() : Response::deny()))->authorize();
        });
    }
}
