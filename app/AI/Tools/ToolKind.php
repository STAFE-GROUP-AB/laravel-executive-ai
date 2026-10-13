<?php

declare(strict_types=1);

namespace App\AI\Tools;

/**
 * Where the tool behind a `tool_runs` row came from. `Mcp` names a tool a server in
 * the S2-03 MCP registry exposes; `ComputerUse` is the ADR-005 automation path, the
 * only kind that can carry an `action_proposals` reference and a screenshot hash.
 */
enum ToolKind: string
{
    case Builtin = 'builtin';
    case Mcp = 'mcp';
    case ComputerUse = 'computer_use';
}
