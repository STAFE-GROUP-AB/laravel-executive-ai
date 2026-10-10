<?php

declare(strict_types=1);

use App\AI\Providers\Dto\AiMessage;
use App\AI\Providers\Dto\AiMessageRole;
use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\Dto\AiToolDefinition;
use App\AI\Providers\ModelCapabilities;
use App\AI\Providers\ModelCapability;
use App\AI\Routing\PrivacyMode;
use App\CRM\Queries\ActivityQuery;
use App\CRM\Queries\QueryPage;
use App\CRM\WorkspaceScope;
use App\Support\Cancellation\CancellationSource;
use App\Support\Cancellation\NeverCancelled;
use App\Support\Cancellation\OperationCancelledException;
use App\Support\Install\Tunnel\TunnelConfig;
use App\Support\Install\Tunnel\TunnelState;

/*
|--------------------------------------------------------------------------
| The little behaviour the contract DTOs carry: fail-closed defaults and
| constructor validation that implementers must be able to rely on.
|--------------------------------------------------------------------------
*/

it('cancels cooperatively through a source and throws from its token', function (): void {
    $source = new CancellationSource;
    $token = $source->token();

    expect($token->isCancelled())->toBeFalse()
        ->and($token->reason())->toBeNull();

    $token->throwIfCancelled();

    $source->cancel('Pause AI actions');

    expect($token->isCancelled())->toBeTrue()
        ->and($token->reason())->toBe('Pause AI actions')
        ->and(fn () => $token->throwIfCancelled())->toThrow(OperationCancelledException::class, 'Pause AI actions');
});

it('never cancels through the explicit NeverCancelled token', function (): void {
    $token = new NeverCancelled;

    $token->throwIfCancelled();

    expect($token->isCancelled())->toBeFalse();
});

it('fails closed for an unknown model: text and streaming only, 4096 context (ADR-004 D3)', function (): void {
    $capabilities = ModelCapabilities::unknown('some-model');

    expect($capabilities->text)->toBeTrue()
        ->and($capabilities->streaming)->toBeTrue()
        ->and($capabilities->tools)->toBeFalse()
        ->and($capabilities->structuredOutput)->toBeFalse()
        ->and($capabilities->vision)->toBeFalse()
        ->and($capabilities->embeddings)->toBeFalse()
        ->and($capabilities->contextWindow)->toBe(4096)
        ->and($capabilities->missing([ModelCapability::Text, ModelCapability::Tools, ModelCapability::Vision]))
        ->toBe([ModelCapability::Tools, ModelCapability::Vision]);
});

it('rejects an AI request without an explicit model (ADR-004 D1, contract row 12)', function (): void {
    expect(fn () => new AiRequest(
        model: ' ',
        messages: [new AiMessage(AiMessageRole::User, 'hello')],
        privacyMode: PrivacyMode::LocalOnly,
        traceId: '01J0000000000000000000TRACE',
    ))->toThrow(InvalidArgumentException::class);
});

it('derives the capabilities a request needs from its content', function (): void {
    $request = new AiRequest(
        model: 'local-model',
        messages: [new AiMessage(AiMessageRole::User, 'summarise this thread')],
        privacyMode: PrivacyMode::LocalOnly,
        traceId: '01J0000000000000000000TRACE',
        tools: [new AiToolDefinition('crm.search', 'Search the CRM', [])],
    );

    expect($request->requiredCapabilities())->toBe([ModelCapability::Text, ModelCapability::Tools]);
});

it('bounds every CRM page and refuses an unanchored timeline', function (): void {
    $scope = new WorkspaceScope('01J00000000000000000000ORG', '01J0000000000000000000USER');

    expect(fn () => new QueryPage(limit: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new QueryPage(limit: QueryPage::MAX_LIMIT + 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ActivityQuery($scope))->toThrow(InvalidArgumentException::class)
        ->and((new ActivityQuery($scope, customerId: '01J000000000000000000000CUST'))->page->limit)->toBe(50);
});

it('refuses a tunnel configuration without a confirmation or on a privileged port (ADR-006 D1)', function (): void {
    expect(fn () => new TunnelConfig(loopbackPort: 80, confirmationId: 'conf'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new TunnelConfig(loopbackPort: 8080, confirmationId: ''))->toThrow(InvalidArgumentException::class)
        ->and((new TunnelConfig(loopbackPort: 8080, confirmationId: 'conf'))->replaceDriftedConfiguration)->toBeFalse();
});

it('allows configuring only from Ready or Drifted, never from FunnelDetected (ADR-006 D2, D6)', function (): void {
    $configurable = array_filter(TunnelState::cases(), fn (TunnelState $state): bool => $state->canConfigure());

    expect(array_values($configurable))->toBe([TunnelState::Ready, TunnelState::Drifted])
        ->and(TunnelState::FunnelDetected->canConfigure())->toBeFalse();
});
