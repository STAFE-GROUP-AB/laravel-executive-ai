<?php

declare(strict_types=1);

use App\Support\Health\Jobs\QueueSelftestJob;
use Illuminate\Contracts\Queue\ShouldQueue;

/*
 * Threat model T-12 / T-75 architecture test: no queued job may declare a
 * property typed App\Security\Secrets\SecretValue (or any class in the
 * Secrets namespace). Jobs carry references and ids; the value is fetched
 * from the Keychain-backed store at run time (ADR-003 D7 row 3).
 */

function queuedJobClasses(): array
{
    $classmap = require base_path('vendor/composer/autoload_classmap.php');
    $jobs = [];

    foreach ($classmap as $class => $file) {
        if (! str_starts_with($class, 'App\\') || ! str_starts_with((string) realpath($file), (string) realpath(base_path('app')))) {
            continue;
        }

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        $jobs[] = $class;
    }

    return $jobs;
}

it('finds the queued jobs of the application', function (): void {
    expect(queuedJobClasses())->toContain(QueueSelftestJob::class);
});

it('declares no job property typed with a secret value class', function (): void {
    foreach (queuedJobClasses() as $class) {
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            $names = [];

            if ($type instanceof ReflectionNamedType) {
                $names[] = $type->getName();
            }

            if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
                foreach ($type->getTypes() as $member) {
                    if ($member instanceof ReflectionNamedType) {
                        $names[] = $member->getName();
                    }
                }
            }

            foreach ($names as $name) {
                expect($name)->not->toStartWith('App\\Security\\Secrets\\', "{$class}::\${$property->getName()} is typed {$name}");
            }

            expect(strtolower($property->getName()))->not->toMatch('/(^|_)(secret|token|password|api_?key)(value)?$/', "{$class}::\${$property->getName()} is named like a secret value");
        }
    }
});

it('never lets a queued job depend on the secrets module', function (): void {
    expect('App\Support\Health\Jobs')->not->toUse('App\Security\Secrets');
});
