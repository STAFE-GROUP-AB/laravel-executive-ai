<?php

declare(strict_types=1);

namespace App\Security\Ipc;

/**
 * `K_test` (IPC contract §4.6) for the test suite only. `IpcKey::forTesting()`
 * refuses outside APP_ENV=testing, so binding this source in any other
 * environment makes every IPC request fail closed with secrets_unavailable.
 */
final class TestingKeySource implements IpcKeySource
{
    public function key(): IpcKey
    {
        try {
            return IpcKey::forTesting();
        } catch (\RuntimeException $exception) {
            throw new IpcKeyUnavailableException('The IPC test key is only available in the testing environment.', previous: $exception);
        }
    }
}
