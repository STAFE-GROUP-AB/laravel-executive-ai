<?php

declare(strict_types=1);

namespace App\Security\Ipc;

/**
 * The two principals of the IPC contract (§2). The value is embedded in the
 * token and inside its MAC, so a token can never change its client.
 */
enum IpcClient: string
{
    case Menubar = 'menubar';
    case Supervisor = 'supervisor';
}
