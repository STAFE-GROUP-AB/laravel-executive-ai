<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Security\Privacy\PrivacyModeStore;
use App\Support\Health\Checks\AuthLoginCheck;
use App\Support\Health\Checks\BriefPlaceholderCheck;
use App\Support\Health\Checks\DatabaseMigrationsCheck;
use App\Support\Health\Checks\HttpCheck;
use App\Support\Health\Checks\MenubarIntegrationCheck;
use App\Support\Health\Checks\QueueWorkerCheck;
use App\Support\Health\Checks\SchedulerSelftestCheck;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Produces the health contract documents (docs/architecture/health-contract.md).
 *
 * `live()` touches nothing. `ready()` runs the seven §12.2 subchecks in
 * contract order with the skip, timeout and budget rules of §2 and §5, then
 * adds the AI, brief, connectivity and approval state. The result is cached
 * for `health.cache_ttl_seconds` so menu bar polling costs one evaluation.
 */
final class HealthService
{
    public const string SCHEMA_VERSION = '1.0.0';

    public const string CACHE_KEY = 'health:ready';

    /** @var array<int, class-string<HealthCheck>> */
    public const array CHECKS = [
        HttpCheck::class,
        DatabaseMigrationsCheck::class,
        AuthLoginCheck::class,
        MenubarIntegrationCheck::class,
        BriefPlaceholderCheck::class,
        QueueWorkerCheck::class,
        SchedulerSelftestCheck::class,
    ];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $cache,
        private readonly AiReadiness $aiReadiness,
        private readonly LatestBrief $latestBrief,
        private readonly Connectivity $connectivity,
        private readonly PendingApprovals $pendingApprovals,
        private readonly PrivacyModeStore $privacyModes,
    ) {}

    /** @return array{status: 'ok'} */
    public function live(): array
    {
        return ['status' => 'ok'];
    }

    public function ready(): ReadyDocument
    {
        $ttl = (int) config('health.cache_ttl_seconds');
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['ready'], $cached['body'])) {
            return new ReadyDocument((bool) $cached['ready'], $cached['body']);
        }

        $document = $this->evaluate();

        $this->cache->put(self::CACHE_KEY, ['ready' => $document->ready, 'body' => $document->body], $ttl);

        return $document;
    }

    public function forgetCached(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    public function evaluate(): ReadyDocument
    {
        $startedAt = hrtime(true);
        $now = CarbonImmutable::now();
        $results = $this->runChecks($startedAt, $now);
        $ready = array_reduce($results, static fn (bool $carry, CheckResult $result): bool => $carry && $result->isOk(), true);

        $ai = $this->aiReadiness->report();
        $brief = $this->latestBrief->resolve();
        $briefState = $this->latestBrief->state($brief, $now);

        $body = [
            'schema_version' => self::SCHEMA_VERSION,
            'ready' => $ready,
            'checks' => array_map(static fn (CheckResult $result): array => $result->toArray(), $results),
            'ai_ready' => $ai->isReady(),
            'ai_state' => $ai->state->value,
            'ai_provider' => $ai->provider,
            'ai_last_success_at' => $ai->lastSuccessAt?->toRfc3339String(),
            'brief_state' => $briefState->value,
            'latest_brief' => $this->latestBrief->summary($brief),
            'connectivity' => $this->connectivity->current(),
            'privacy_mode' => $this->privacyMode(),
            'pending_approvals' => max(0, $this->pendingApprovals->count()),
            'checked_at' => $now->toRfc3339String(),
            'duration_ms' => min((int) config('health.total_budget_ms'), $this->elapsedMs($startedAt)),
        ];

        return new ReadyDocument($ready, $body);
    }

    /** @return array<int, CheckResult> */
    private function runChecks(int $startedAt, CarbonImmutable $now): array
    {
        $checkTimeoutMs = (int) config('health.check_timeout_ms');
        $totalBudgetMs = (int) config('health.total_budget_ms');
        $results = [];
        $failed = [];

        foreach (self::CHECKS as $class) {
            /** @var HealthCheck $check */
            $check = $this->container->make($class);
            $name = $check->name();

            $failedDependency = array_values(array_intersect($check->dependsOn(), $failed))[0] ?? null;

            if ($failedDependency !== null) {
                $results[] = new CheckResult($name, CheckOutcome::skip("skipped: {$failedDependency} failed"), $now, 0);
                $failed[] = $name;

                continue;
            }

            if ($this->elapsedMs($startedAt) >= $totalBudgetMs) {
                $results[] = new CheckResult($name, CheckOutcome::skip('budget exhausted'), $now, 0);
                $failed[] = $name;

                continue;
            }

            $checkStartedAt = hrtime(true);
            $outcome = $this->runSafely($check);
            $durationMs = $this->elapsedMs($checkStartedAt);

            if ($durationMs > $checkTimeoutMs) {
                $outcome = CheckOutcome::fail('timeout');
                $durationMs = $checkTimeoutMs;
            }

            $result = new CheckResult($name, $outcome, $now, $durationMs);
            $results[] = $result;

            if (! $result->isOk()) {
                $failed[] = $name;
            }
        }

        return $results;
    }

    private function runSafely(HealthCheck $check): CheckOutcome
    {
        try {
            return $check->run();
        } catch (Throwable $exception) {
            $class = (new \ReflectionClass($exception))->getShortName();

            return CheckOutcome::fail("check failed: {$class}");
        }
    }

    private function elapsedMs(int $sinceHrtime): int
    {
        return (int) intdiv(hrtime(true) - $sinceHrtime, 1_000_000);
    }

    /**
     * The mode actually in force, read from `privacy_policies` (S2-14,
     * OPEN-QUESTIONS A18). Reporting `config('lea.privacy_mode')` here would
     * make `/health/ready` and the menu bar say `LOCAL_ONLY` while a stored
     * policy had widened the boundary — threat **T-68**'s "the UI may still
     * suggest otherwise" in one line of code.
     */
    private function privacyMode(): string
    {
        return $this->privacyModes->active()->configValue();
    }
}
