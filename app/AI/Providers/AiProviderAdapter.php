<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\Dto\AiResponse;
use App\AI\Providers\Dto\AiStreamChunk;
use App\AI\Providers\Exceptions\CapabilityUnavailableException;
use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Providers\Exceptions\InvalidStructuredOutputException;
use App\AI\Providers\Exceptions\ProviderUnavailableException;
use App\Support\Cancellation\CancellationToken;
use App\Support\Cancellation\OperationCancelledException;
use Generator;

/**
 * Blueprint §3, first contract: `AiProviderAdapter` with `capabilities()`,
 * `generate()` and `stream()`. One instance represents one configured provider row
 * (`ai_providers`) with credentials resolved from the Keychain at call time
 * (ADR-003); the model is chosen per request.
 *
 * Deliberate differences from the §3 sketch (see docs/architecture/module-contracts.md):
 * `capabilities()` takes the model id because capabilities are per model, not per
 * provider (ADR-004 D3); every call takes a CancellationToken; `stream()` returns a
 * typed Generator whose return value is the completed AiResponse.
 *
 * Implementations live under `App\AI\Providers\LaravelAi` and are the only code
 * allowed to import `Laravel\Ai` (ADR-004 D2). The model proposes; it never executes.
 * Tool calls in the response are proposals for the PolicyGate, and nothing an
 * adapter returns carries authority.
 */
interface AiProviderAdapter
{
    /**
     * Stable id of the `ai_providers` row this adapter was built from.
     */
    public function providerId(): string;

    /**
     * SDK driver name (`ollama`, `openai-compatible`, `openai`, `anthropic`, ...).
     */
    public function driver(): string;

    /**
     * Capabilities of `$modelId` on this provider, resolved registry > probe >
     * user override; `ModelCapabilities::unknown()` when nothing is known.
     */
    public function capabilities(string $modelId): ModelCapabilities;

    /**
     * @throws CapabilityUnavailableException the model lacks a capability the request needs
     * @throws EgressBlockedException Local Only mode refused a non-loopback host
     * @throws ProviderUnavailableException the provider failed; retry the same provider later, never another
     * @throws InvalidStructuredOutputException the model's JSON did not satisfy `outputSchema`
     * @throws OperationCancelledException the token was cancelled before completion
     */
    public function generate(AiRequest $request, CancellationToken $cancellation): AiResponse;

    /**
     * Yields chunks as the provider emits them; the generator's return value is the
     * completed response. Cancellation stops iteration within one chunk (ADR-004 D2
     * row 6). Provider failures after the first chunk arrive as an `Error` chunk,
     * failures before it as an exception.
     *
     * @return Generator<int, AiStreamChunk, null, AiResponse>
     *
     * @throws CapabilityUnavailableException
     * @throws EgressBlockedException
     * @throws ProviderUnavailableException
     * @throws OperationCancelledException
     */
    public function stream(AiRequest $request, CancellationToken $cancellation): Generator;
}
