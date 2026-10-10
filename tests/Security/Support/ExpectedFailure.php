<?php

declare(strict_types=1);

namespace Tests\Security\Support;

use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\SkippedWithMessageException;
use Throwable;

/**
 * Real xfail semantics for a security gate that is written before its defence
 * (issue #21 criterion 1: "explicitly marked as an expected-failing gate ... so a
 * green CI is not faked by skipping it").
 *
 * Pest and PHPUnit have no native expected-failure state, and the three mechanisms
 * that look like one are all forbidden here:
 *
 * - `->todo()` and `markTestSkipped()` do not run the body at all, so the gate stops
 *   being executable documentation the moment it is written;
 * - `markTestIncomplete()` runs part of the body but hides the reason behind a single
 *   status, and nothing fails when the defence lands;
 * - a plain failing test turns CI red forever, which trains reviewers to ignore it.
 *
 * So this helper does what an xfail must do:
 *
 * 1. the body **runs**, with real fakes and real assertions;
 * 2. if it fails, the failure is reported on STDERR with the issue link, the exact
 *    removal condition and the concrete current failure, and the test stays green —
 *    the gate is visible in CI output without blocking the sprint;
 * 3. if it **passes**, the test FAILS, with "remove the expected-failure marker",
 *    so the defence landing forces the marker out instead of leaving a permanently
 *    lenient test in the suite;
 * 4. `markTestSkipped()` / `markTestIncomplete()` inside the body are re-thrown
 *    rather than absorbed: skipping is precisely what criterion 1 forbids, and a
 *    skip must never be laundered into an expected failure.
 *
 * `tests/Security/ExpectedFailureTest.php` proves both directions.
 */
final class ExpectedFailure
{
    public const string ISSUE_BASE_URL = 'https://github.com/STAFE-GROUP-AB/laravel-executive-ai/issues/';

    /**
     * @param  string  $issue  the issue that owns the gate, `#21` style
     * @param  string  $item  the backlog item that committed the gate, `S0-21` style
     * @param  string  $until  `<item>: <the condition under which the marker is removed>`;
     *                         the leading token names the item that must remove it
     * @param  Closure():void  $assertions  the boundary assertions; they run on every CI run
     * @param  (Closure(string):void)|null  $reporter  where the expected-failure line goes;
     *                                                 STDERR by default, injected by the self-test
     *
     * @throws AssertionFailedError the assertions passed and the marker must now be removed
     */
    public static function guard(
        string $issue,
        string $item,
        string $until,
        Closure $assertions,
        ?Closure $reporter = null,
    ): void {
        Assert::assertStringStartsWith(
            '#',
            $issue,
            'An expected-failure gate names the issue that owns it, so CI output links back to the decision.',
        );
        Assert::assertStringContainsString(
            ':',
            $until,
            'An expected-failure gate states `<item>: <removal condition>`, so the marker has an owner.',
        );

        $report = $reporter ?? self::stderr();

        try {
            $assertions();
        } catch (SkippedWithMessageException|IncompleteTestError $sidestep) {
            throw $sidestep;
        } catch (Throwable $failure) {
            $report(self::expectedFailureLine($issue, $item, $until, $failure));

            return;
        }

        throw new AssertionFailedError(self::unexpectedPassLine($issue, $item, $until));
    }

    /**
     * The item that owns the removal, taken from the `until` prefix (`E2-4: ...`).
     */
    public static function owner(string $until): string
    {
        $owner = trim((string) strstr($until, ':', true));

        return $owner === '' ? 'the owning sprint item' : $owner;
    }

    private static function expectedFailureLine(string $issue, string $item, string $until, Throwable $failure): string
    {
        return implode(PHP_EOL, [
            '',
            sprintf('EXPECTED FAILURE %s (%s) — %s', $issue, $item, self::ISSUE_BASE_URL.ltrim($issue, '#')),
            sprintf('  still failing by design: %s: %s', $failure::class, self::firstLine($failure->getMessage())),
            sprintf('  at %s:%d', self::relativePath($failure->getFile()), $failure->getLine()),
            sprintf('  remove the marker when — %s', $until),
            sprintf('  CI stays green until then; an unexpected pass fails the build and forces %s to remove it.', self::owner($until)),
            '',
        ]);
    }

    private static function unexpectedPassLine(string $issue, string $item, string $until): string
    {
        return implode(PHP_EOL, [
            sprintf('remove the expected-failure marker (%s → %s).', $item, self::owner($until)),
            '',
            sprintf('The boundary assertions behind %s now pass, which is the whole point of the gate:', $issue),
            sprintf('  %s', $until),
            '',
            sprintf('Delete the ExpectedFailure::guard() wrapper in this test and let the assertions stand on their own,'.PHP_EOL.'then move the threat-model rows this gate covers from `planned` to `mitigated` (%s).', self::ISSUE_BASE_URL.ltrim($issue, '#')),
        ]);
    }

    /**
     * CI output and PR bodies quote this line, so it carries a repository-relative
     * path and never the checkout directory of whoever ran it.
     */
    private static function relativePath(string $path): string
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }

    private static function firstLine(string $message): string
    {
        $trimmed = trim((string) strstr($message.PHP_EOL, PHP_EOL, true));

        return $trimmed === '' ? '(no message)' : $trimmed;
    }

    /**
     * @return Closure(string):void
     */
    private static function stderr(): Closure
    {
        return static function (string $line): void {
            $handle = fopen('php://stderr', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, $line.PHP_EOL);
            fclose($handle);
        };
    }
}
