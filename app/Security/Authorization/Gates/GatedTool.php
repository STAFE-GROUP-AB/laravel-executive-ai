<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

use Illuminate\Database\Eloquent\Model;

/**
 * What an AI tool or an MCP tool must declare before `ToolAuthorizer` will run
 * it (blueprint §4 "the LLM proposes, the server-side PolicyGate decides"; §11).
 *
 * The model picks the tool and the arguments, so neither can be trusted to
 * carry identity: the tool names the **ability** it needs and the **row** it
 * intends to touch, and the gate decides with the dispatching user's
 * memberships. A tool that returns a row from another organization in
 * `subject()` is refused by that row's policy — the same policy the PWA runs,
 * which is the whole point of T-73's "nothing bypasses the check by going
 * through a tool instead of a controller".
 *
 * `PolicySurfaceArchTest` requires every executable class under `app/AI/Tools/`
 * and `app/MCP/` to implement this interface, so the first tool that is written
 * without it fails CI rather than review.
 */
interface GatedTool
{
    /** The tool name as the model sees it, and as `tool_runs.tool_name` records it. */
    public function name(): string;

    /** The named ability this call needs — an `Abilities` CRUD ability, or a gate. */
    public function ability(): string;

    /**
     * The row the call intends to touch, or the model class for a `create` or
     * `viewAny` ability, or null for an ability that is about no row at all.
     *
     * @param  array<string, mixed>  $arguments  the arguments the model proposed
     */
    public function subject(array $arguments): Model|string|null;
}
