<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;
use Tests\Security\Support\ExpectedFailure;

/*
|--------------------------------------------------------------------------
| The expected-failure mechanism, proved in both directions
|--------------------------------------------------------------------------
|
| `ForeignEmailCannotTriggerSendTest` is green today while the boundary it
| asserts does not hold. That is only acceptable if the mechanism making it
| green is itself tested, otherwise a broken helper would silence the gate
| for the rest of the sprint — the exact outcome issue #21 criterion 1
| forbids ("so a green CI is not faked by skipping it").
|
| So: a failing body is reported and tolerated, a passing body fails the
| build, and a skip is never laundered into an expected failure.
|
*/

it('reports a failing gate, stays green, and names the issue and the removal condition', function (): void {
    $reported = [];

    ExpectedFailure::guard(
        issue: '#21',
        item: 'S0-21',
        until: 'E2-4: PolicyGate refuses the proposal and this test passes',
        assertions: function (): void {
            throw new RuntimeException('no PolicyGate is bound yet');
        },
        reporter: function (string $line) use (&$reported): void {
            $reported[] = $line;
        },
    );

    expect($reported)->toHaveCount(1);

    expect($reported[0])
        ->toContain('EXPECTED FAILURE #21 (S0-21)')
        ->toContain(ExpectedFailure::ISSUE_BASE_URL.'21')
        ->toContain('RuntimeException: no PolicyGate is bound yet')
        ->toContain('remove the marker when — E2-4: PolicyGate refuses the proposal and this test passes')
        ->toContain('an unexpected pass fails the build and forces E2-4 to remove it');
});

it('fails the build when the gate unexpectedly passes', function (): void {
    $reported = [];

    $unexpectedPass = function () use (&$reported): void {
        ExpectedFailure::guard(
            issue: '#21',
            item: 'S0-21',
            until: 'E2-4: PolicyGate refuses the proposal and this test passes',
            assertions: function (): void {
                expect(true)->toBeTrue();
            },
            reporter: function (string $line) use (&$reported): void {
                $reported[] = $line;
            },
        );
    };

    expect($unexpectedPass)->toThrow(AssertionFailedError::class, 'remove the expected-failure marker (S0-21 → E2-4)');

    expect($reported)->toBe([], 'a passing gate is not an expected failure and must not be reported as one');
});

it('refuses to launder a skipped test into an expected failure', function (): void {
    $skippedBody = function (): void {
        ExpectedFailure::guard(
            issue: '#21',
            item: 'S0-21',
            until: 'E2-4: PolicyGate refuses the proposal and this test passes',
            assertions: function (): void {
                throw new SkippedWithMessageException('pretending the gate is not ready');
            },
            reporter: function (string $line): void {
                throw new RuntimeException('a skipped body must never be reported as an expected failure: '.$line);
            },
        );
    };

    expect($skippedBody)->toThrow(SkippedWithMessageException::class, 'pretending the gate is not ready');
});

it('insists that a gate names its issue and the item that must remove it', function (): void {
    $withoutIssue = function (): void {
        ExpectedFailure::guard(
            issue: '21',
            item: 'S0-21',
            until: 'E2-4: PolicyGate lands',
            assertions: function (): void {},
        );
    };

    $withoutOwner = function (): void {
        ExpectedFailure::guard(
            issue: '#21',
            item: 'S0-21',
            until: 'someday',
            assertions: function (): void {},
        );
    };

    expect($withoutIssue)->toThrow(AssertionFailedError::class, 'names the issue that owns it');
    expect($withoutOwner)->toThrow(AssertionFailedError::class, 'states `<item>: <removal condition>`');
});

it('derives the item that owns the removal from the until clause', function (): void {
    expect(ExpectedFailure::owner('E2-4: PolicyGate lands'))->toBe('E2-4')
        ->and(ExpectedFailure::owner('no colon here'))->toBe('the owning sprint item');
});
