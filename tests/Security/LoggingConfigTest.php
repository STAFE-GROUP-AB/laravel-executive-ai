<?php

declare(strict_types=1);

use App\Security\Logging\RedactSecretsProcessor;
use App\Security\Logging\RedactSecretsTap;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as Monolog;

/*
 * Threat model T-75 / ADR-003 D7 row 1: every log channel carries the
 * redaction tap. A channel added without it fails this build.
 */

it('lists the redaction tap on every configured channel', function (): void {
    $channels = config('logging.channels');

    foreach ($channels as $name => $channel) {
        if ($name === 'emergency') {
            expect(array_keys($channel))->toBe(['path'], 'emergency is the only tap-less entry and carries nothing but a path');

            continue;
        }

        expect(in_array(RedactSecretsTap::class, $channel['tap'] ?? [], true))->toBeTrue("channel `{$name}` lacks RedactSecretsTap");
    }

    expect(count($channels))->toBeGreaterThanOrEqual(10);
});

it('resolves each locally constructible channel with the processor installed', function (string $name): void {
    $channel = Log::channel($name);

    if (! $channel instanceof Logger) {
        throw new RuntimeException("channel `{$name}` did not resolve to an Illuminate logger");
    }

    $monolog = $channel->getLogger();

    if (! $monolog instanceof Monolog) {
        throw new RuntimeException("channel `{$name}` is not backed by Monolog");
    }

    $processors = array_filter($monolog->getProcessors(), fn (callable $processor): bool => $processor instanceof RedactSecretsProcessor);

    expect($processors)->not->toBeEmpty("channel `{$name}` has no RedactSecretsProcessor");
})->with(['stack', 'single', 'daily', 'monthly', 'stderr', 'syslog', 'errorlog', 'null']);

it('keeps the default channel inside the tapped set', function (): void {
    expect(config('logging.default'))->toBeIn(array_keys(config('logging.channels')))
        ->and(config('logging.channels.'.config('logging.default').'.tap'))->toContain(RedactSecretsTap::class);
});
