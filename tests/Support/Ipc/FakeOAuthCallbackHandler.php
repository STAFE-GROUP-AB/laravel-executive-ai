<?php

declare(strict_types=1);

namespace Tests\Support\Ipc;

use App\Security\Ipc\IpcPrincipal;
use App\Security\Ipc\OAuth\OAuthCallbackHandler;
use App\Security\Ipc\OAuth\OAuthCallbackResult;
use Closure;

/**
 * Scripted §6.6 exchange: returns the given result, or runs the closure
 * (which may throw) so the redaction tests can simulate a careless adapter.
 */
final class FakeOAuthCallbackHandler implements OAuthCallbackHandler
{
    /** @var list<array{state: string, code: string}> */
    public array $calls = [];

    public function __construct(private readonly OAuthCallbackResult|Closure $outcome) {}

    public function complete(#[\SensitiveParameter] string $state, #[\SensitiveParameter] string $code, IpcPrincipal $principal): OAuthCallbackResult
    {
        $this->calls[] = ['state' => $state, 'code' => $code];

        if ($this->outcome instanceof Closure) {
            return ($this->outcome)($state, $code, $principal);
        }

        return $this->outcome;
    }
}
