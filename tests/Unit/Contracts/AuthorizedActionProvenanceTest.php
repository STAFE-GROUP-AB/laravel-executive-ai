<?php

declare(strict_types=1);

use App\Browser\ComputerUse\AuthorizedUiAction;
use App\Integrations\Mail\Dto\ApprovedOutgoingEmail;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Blueprint §4 / §11: the LLM proposes, it never has permissions
|--------------------------------------------------------------------------
|
| `ComputerUseProvider::execute()` accepts only an AuthorizedUiAction and
| `EmailConnector::send()` accepts only an ApprovedOutgoingEmail. PHP cannot
| restrict a constructor to one namespace, so this test does: the only
| production code allowed to instantiate an authorized type is the policy
| layer under app/Approvals. Everything else (agents, tools, MCP handlers,
| controllers, connectors) must hand a proposal to the policy layer and wait
| for approval. S0-21 builds the negative security test on the same boundary.
|
*/

/** @var array<string, array{class-string, string, list<string>}> */
$authorizedTypes = [
    'AuthorizedUiAction' => [
        AuthorizedUiAction::class,
        'App\\\\Browser\\\\ComputerUse\\\\',
        ['proposalId', 'approvalId', 'approverUserId', 'proposalHash', 'nonce', 'expiresAt'],
    ],
    'ApprovedOutgoingEmail' => [
        ApprovedOutgoingEmail::class,
        'App\\\\Integrations\\\\Mail\\\\Dto\\\\',
        ['proposalId', 'approvalId', 'approverUserId', 'connectionId', 'from', 'to', 'bodyHash', 'nonce', 'approvedAt', 'expiresAt'],
    ],
];

dataset('authorized types', $authorizedTypes);

it('lets only the policy layer construct an authorized action type', function (string $class, string $namespacePattern): void {
    $shortName = class_basename($class);
    $offenders = [];

    $files = Finder::create()
        ->files()
        ->in(app_path())
        ->notPath('Approvals')
        ->name('*.php')
        ->contains($shortName);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getRealPath());

        if (preg_match('/new\s+(\\\\?'.$namespacePattern.')?'.$shortName.'\s*\(/', $source) === 1) {
            $offenders[] = $file->getRelativePathname();
        }

        if (preg_match('/'.$shortName.'::(from|make|create|build|of)\w*\s*\(/', $source) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
})->with('authorized types');

it('gives an authorized action type no way to be built without an approval reference', function (string $class, string $namespacePattern, array $requiredFields): void {
    $constructor = (new ReflectionClass($class))->getConstructor();

    expect($constructor)->not->toBeNull();

    $required = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        array_filter(
            $constructor->getParameters(),
            fn (ReflectionParameter $parameter): bool => ! $parameter->isOptional(),
        ),
    );

    expect($required)->toContain(...$requiredFields);
})->with('authorized types');
