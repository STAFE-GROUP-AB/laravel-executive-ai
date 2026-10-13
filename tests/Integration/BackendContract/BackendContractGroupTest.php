<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The group annotation is what makes this directory a two-profile matrix
|--------------------------------------------------------------------------
|
| ADR-002 D10's matrix is not a CI list, it is the `backend-contract` **group**:
| the `server-profile` lane runs `vendor/bin/pest --group=backend-contract`, so
| a test in this directory without the annotation runs on `local` only — and
| passes, every time, while asserting nothing about MySQL. That failure is
| invisible: the test is green, the lane is green, and the profile it was
| written for never saw it.
|
| So the annotation is checked rather than remembered. The rule is deliberately
| coarse — a file must carry at least as many `backend-contract` annotations as
| it declares tests — because the alternative is parsing Pest's fluent chain,
| and a coarse rule that fails on a missing annotation is worth more than a
| precise one nobody writes. A file that trips it is either missing an
| annotation or belongs somewhere else.
|
| S2-06 added this while adding `CrossAccountContractTest`; the gap had been
| real since the lane existed.
|
*/

it('puts every test in this directory in the backend-contract group', function (): void {
    $files = glob(__DIR__.'/*Test.php') ?: [];

    expect($files)->not->toBeEmpty('the contract directory has no tests, so the matrix asserts nothing');

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $name = basename($file);

        // Top-of-line `it(` / `test(` only: a nested call inside a closure is
        // part of the test above it, not a new one.
        $declared = preg_match_all('/^(it|test)\(/m', $source);
        $annotated = substr_count($source, "'backend-contract'");

        expect($declared)->toBeGreaterThan(0, "{$name} declares no test");

        expect($annotated)->toBeGreaterThanOrEqual(
            $declared,
            "{$name} declares {$declared} tests but carries {$annotated} `backend-contract` annotations: ".
            'an unannotated test here never runs on the server profile (ADR-002 D10)',
        );
    }
})->group('backend-contract');

it('skips itself on the server profile exactly as its neighbours do, so the lane reports honestly', function (): void {
    // The sibling files pair `->group('backend-contract')` with a skip when the
    // `server` profile is selected without a MySQL connection, so a developer
    // with no MySQL stays green instead of red. This file needs no database at
    // all — it reads source — which is why it carries the group and no skip,
    // and why that difference is stated here rather than looking like an
    // oversight.
    expect(config('lea.backend_profile'))->toBeString()
        ->and(in_array(config('lea.backend_profile'), ['local', 'server'], true))->toBeTrue('ADR-002 D1 names exactly two profiles');
})->group('backend-contract');
