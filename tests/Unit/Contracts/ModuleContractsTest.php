<?php

declare(strict_types=1);

use App\AI\Providers\AiProviderAdapter;
use App\AI\Providers\Dto\AiAttachment;
use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiMessage;
use App\AI\Providers\Dto\AiRequest;
use App\AI\Providers\Dto\AiResponse;
use App\AI\Providers\Dto\AiStreamChunk;
use App\AI\Providers\Dto\AiToolCall;
use App\AI\Providers\Dto\AiToolDefinition;
use App\AI\Providers\Dto\AiUsage;
use App\AI\Providers\ModelCapabilities;
use App\Browser\ComputerUse\ActionResult;
use App\Browser\ComputerUse\AllowlistEntry;
use App\Browser\ComputerUse\AuthorizedUiAction;
use App\Browser\ComputerUse\AutomationCapabilities;
use App\Browser\ComputerUse\AutomationPermissions;
use App\Browser\ComputerUse\AutomationSession;
use App\Browser\ComputerUse\ComputerUseProvider;
use App\Browser\ComputerUse\Observation;
use App\Browser\ComputerUse\ObservationRequest;
use App\Browser\ComputerUse\Rect;
use App\Browser\ComputerUse\UiAction;
use App\Browser\ComputerUse\UiElement;
use App\CRM\Communications\ActivityEntry;
use App\CRM\Contacts\ContactSummary;
use App\CRM\Customers\CustomerSummary;
use App\CRM\Projects\ProjectSummary;
use App\CRM\Queries\ActivityQuery;
use App\CRM\Queries\ContactQuery;
use App\CRM\Queries\CustomerQuery;
use App\CRM\Queries\ProjectQuery;
use App\CRM\Queries\QueryPage;
use App\CRM\Queries\TaskQuery;
use App\CRM\Tasks\TaskSummary;
use App\CRM\WorkspaceRepository;
use App\CRM\WorkspaceScope;
use App\Support\Cancellation\CancellationToken;
use App\Support\Cancellation\NeverCancelled;
use App\Support\Install\Tunnel\TunnelConfig;
use App\Support\Install\Tunnel\TunnelResult;
use App\Support\Install\Tunnel\TunnelStatus;
use App\Support\Install\Tunnel\TunnelVerificationStep;
use App\Support\Install\TunnelProvider;

/*
|--------------------------------------------------------------------------
| S0-16 — architecture tests for the blueprint §3 module contracts
|--------------------------------------------------------------------------
|
| These tests pin the *shape* of the contracts, not any behaviour: every
| interface exists and is an interface, every method is fully typed with no
| `mixed`, every array/iterable/Generator carries a generics docblock, failure
| and cancellation are declared in the signatures, and every DTO is a final
| readonly value object. Sprint 2 implementers can refactor freely behind
| these assertions; breaking one is a contract change that needs a review.
|
*/

/** @var array<string, array{class-string, string}> */
$contracts = [
    'AiProviderAdapter' => [AiProviderAdapter::class, '§3'],
    'WorkspaceRepository' => [WorkspaceRepository::class, '§3'],
    'ComputerUseProvider' => [ComputerUseProvider::class, '§3'],
    'TunnelProvider' => [TunnelProvider::class, '§3'],
];

dataset('contracts', $contracts);

/** @var list<class-string> */
$dtos = [
    ModelCapabilities::class,
    AiRequest::class,
    AiResponse::class,
    AiMessage::class,
    AiAttachment::class,
    AiToolDefinition::class,
    AiToolCall::class,
    AiUsage::class,
    AiError::class,
    AiStreamChunk::class,
    WorkspaceScope::class,
    QueryPage::class,
    CustomerQuery::class,
    ContactQuery::class,
    ProjectQuery::class,
    TaskQuery::class,
    ActivityQuery::class,
    CustomerSummary::class,
    ContactSummary::class,
    ProjectSummary::class,
    TaskSummary::class,
    ActivityEntry::class,
    AutomationSession::class,
    AllowlistEntry::class,
    Observation::class,
    ObservationRequest::class,
    UiElement::class,
    Rect::class,
    UiAction::class,
    AuthorizedUiAction::class,
    ActionResult::class,
    AutomationCapabilities::class,
    AutomationPermissions::class,
    TunnelStatus::class,
    TunnelConfig::class,
    TunnelResult::class,
    TunnelVerificationStep::class,
    NeverCancelled::class,
];

dataset('dtos', array_combine(
    array_map(fn (string $class): string => class_basename($class), $dtos),
    array_map(fn (string $class): array => [$class], $dtos),
));

/**
 * Native types that are only acceptable with a generics docblock.
 */
const S0_16_GENERIC_CARRIERS = ['array', 'iterable', 'Generator'];

/**
 * @return list<string>
 */
function s0_16_native_type_names(?ReflectionType $type): array
{
    if ($type === null) {
        return [];
    }

    if ($type instanceof ReflectionNamedType) {
        return [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        return array_merge(...array_map(
            fn (ReflectionType $inner): array => s0_16_native_type_names($inner),
            $type->getTypes(),
        ));
    }

    return [];
}

function s0_16_docblock_declares_generic(ReflectionMethod $method, string $tag, ?string $parameterName = null): bool
{
    $docblock = $method->getDocComment();

    if ($docblock === false) {
        return false;
    }

    $suffix = $parameterName === null ? '(?!\S)' : '\s+\$'.preg_quote($parameterName, '/').'(?!\w)';

    return preg_match('/@'.$tag.'\s+\??[\w\\\\]+<[^>]+>(\|null)?'.$suffix.'/', $docblock) === 1;
}

/**
 * @return list<string> human-readable violations, empty when the method is clean
 */
function s0_16_method_violations(ReflectionMethod $method): array
{
    $violations = [];
    $label = $method->getDeclaringClass()->getShortName().'::'.$method->getName();

    foreach ($method->getParameters() as $parameter) {
        $names = s0_16_native_type_names($parameter->getType());

        if ($names === []) {
            $violations[] = "{$label}() parameter \${$parameter->getName()} has no type";

            continue;
        }

        if (in_array('mixed', $names, true)) {
            $violations[] = "{$label}() parameter \${$parameter->getName()} is mixed";
        }

        foreach (array_intersect($names, S0_16_GENERIC_CARRIERS) as $carrier) {
            if (! s0_16_docblock_declares_generic($method, 'param', $parameter->getName())) {
                $violations[] = "{$label}() parameter \${$parameter->getName()} is {$carrier} without a generics @param";
            }
        }
    }

    if ($method->isConstructor()) {
        return $violations;
    }

    $returnNames = s0_16_native_type_names($method->getReturnType());

    if ($returnNames === []) {
        $violations[] = "{$label}() has no return type";

        return $violations;
    }

    if (in_array('mixed', $returnNames, true)) {
        $violations[] = "{$label}() returns mixed";
    }

    foreach (array_intersect($returnNames, S0_16_GENERIC_CARRIERS) as $carrier) {
        if (! s0_16_docblock_declares_generic($method, 'return')) {
            $violations[] = "{$label}() returns {$carrier} without a generics @return";
        }
    }

    return $violations;
}

it('declares each §3 contract as an interface whose docblock cites its blueprint paragraph', function (string $class, string $paragraph): void {
    expect(interface_exists($class))->toBeTrue("{$class} must exist");

    $reflection = new ReflectionClass($class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->getDocComment())->toBeString()
        ->and((string) $reflection->getDocComment())->toContain($paragraph);
})->with('contracts');

it('types every contract method explicitly, with no mixed and generics on every iterable', function (string $class): void {
    $violations = [];

    foreach ((new ReflectionClass($class))->getMethods() as $method) {
        $violations = [...$violations, ...s0_16_method_violations($method)];
    }

    expect($violations)->toBe([]);
})->with('contracts');

it('makes failure and cancellation expressible in the contract signatures', function (string $class): void {
    $reflection = new ReflectionClass($class);
    $methods = $reflection->getMethods();

    $acceptsCancellation = array_filter(
        $methods,
        fn (ReflectionMethod $method): bool => array_filter(
            $method->getParameters(),
            fn (ReflectionParameter $parameter): bool => in_array(
                CancellationToken::class,
                s0_16_native_type_names($parameter->getType()),
                true,
            ),
        ) !== [],
    );

    $declaresThrows = array_filter(
        $methods,
        fn (ReflectionMethod $method): bool => str_contains((string) $method->getDocComment(), '@throws'),
    );

    expect($acceptsCancellation)->not->toBeEmpty("{$class} has no method accepting a CancellationToken")
        ->and($declaresThrows)->not->toBeEmpty("{$class} declares no @throws");
})->with('contracts');

it('ships each DTO as a final readonly value object with a fully typed constructor', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue("{$class} must be final")
        ->and($reflection->isReadOnly())->toBeTrue("{$class} must be readonly");

    $violations = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $violations = [...$violations, ...s0_16_method_violations($method)];
    }

    expect($violations)->toBe([]);
})->with('dtos');

it('keeps every §3 contract and DTO in the §2.1 module it belongs to', function (): void {
    expect(AiProviderAdapter::class)->toStartWith('App\AI\Providers\\')
        ->and(WorkspaceRepository::class)->toBe('App\CRM\WorkspaceRepository')
        ->and(ComputerUseProvider::class)->toBe('App\Browser\ComputerUse\ComputerUseProvider')
        ->and(TunnelProvider::class)->toBe('App\Support\Install\TunnelProvider');
});

test('App\AI never imports the laravel/ai SDK outside the adapter implementation (ADR-004 D2)', function (): void {
    expect('App\AI')->not->toUse('Laravel\Ai')->ignoring('App\AI\Providers\LaravelAi');
});

test('contract modules do not depend on Eloquent, HTTP or Livewire concerns', function (): void {
    expect(['App\AI\Providers', 'App\CRM', 'App\Browser\ComputerUse', 'App\Support\Install', 'App\Support\Cancellation'])
        ->not->toUse(['Illuminate\Database\Eloquent', 'Illuminate\Http', 'Livewire']);
});
