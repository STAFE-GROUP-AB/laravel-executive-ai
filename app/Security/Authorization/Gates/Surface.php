<?php

declare(strict_types=1);

namespace App\Security\Authorization\Gates;

/**
 * The five paths threat model T-73 names, plus the console. Every audit row an
 * authorization decision writes carries one, so "the check ran" and "the check
 * ran on the path the attacker used" are two different readable facts.
 *
 * The HTTP surfaces are recognised from the request; `Job` and `Tool` are
 * pushed explicitly by `AuthorizesQueuedWork` and `ToolAuthorizer`, because a
 * queued job and a tool call both run without a request.
 */
enum Surface: string
{
    case Pwa = 'pwa';

    case Ipc = 'ipc';

    case Job = 'job';

    case Tool = 'tool';

    case Console = 'console';
}
