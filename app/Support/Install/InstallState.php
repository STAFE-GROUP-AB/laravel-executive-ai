<?php

declare(strict_types=1);

namespace App\Support\Install;

/**
 * Install-state marker (§12.1, §16 step 4). `core_ready` is §12.2 ready
 * without a model; `ai_ready` additionally requires a recorded successful
 * completion. The health endpoint does not read it (health contract §9).
 */
enum InstallState: string
{
    case NotInstalled = 'not_installed';
    case Installing = 'installing';
    case CoreReady = 'core_ready';
    case AiReady = 'ai_ready';

    public function isReady(): bool
    {
        return in_array($this, [self::CoreReady, self::AiReady], true);
    }
}
