<?php

declare(strict_types=1);

use App\Browser\ComputerUse\AuthorizedUiAction;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Blueprint §4 / §11: the LLM proposes, it never has permissions
|--------------------------------------------------------------------------
|
| `ComputerUseProvider::execute()` accepts only an AuthorizedUiAction. PHP cannot
| restrict a constructor to one namespace, so this test does: the only production
| code allowed to instantiate the authorized type is the policy layer under
| app/Approvals. Everything else (agents, tools, MCP handlers, controllers) must
| hand a proposal to the policy layer and wait for approval. S0-21 builds the
| negative security test on the same boundary.
|
*/

it('lets only the policy layer construct an AuthorizedUiAction', function (): void {
    $offenders = [];

    $files = Finder::create()
        ->files()
        ->in(app_path())
        ->notPath('Approvals')
        ->name('*.php')
        ->contains('AuthorizedUiAction');

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getRealPath());

        if (preg_match('/new\s+(\\\\?App\\\\Browser\\\\ComputerUse\\\\)?AuthorizedUiAction\s*\(/', $source) === 1) {
            $offenders[] = $file->getRelativePathname();
        }

        if (preg_match('/AuthorizedUiAction::(from|make|create)\w*\s*\(/', $source) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

it('gives AuthorizedUiAction no way to be built without an approval reference', function (): void {
    $constructor = (new ReflectionClass(AuthorizedUiAction::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    $required = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        array_filter(
            $constructor->getParameters(),
            fn (ReflectionParameter $parameter): bool => ! $parameter->isOptional(),
        ),
    );

    expect($required)->toContain('proposalId', 'approvalId', 'approverUserId', 'proposalHash', 'nonce', 'expiresAt');
});
