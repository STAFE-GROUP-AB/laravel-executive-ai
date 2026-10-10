<?php

declare(strict_types=1);

namespace App\Support\Health;

/**
 * What a check reports before the runner adds timing. `detail` is sanitised
 * and ≤ 500 characters: never a token, credential, mail content, user-revealing
 * path or stack trace (contract §2 `Check`).
 */
final readonly class CheckOutcome
{
    private function __construct(
        public CheckStatus $status,
        public string $detail,
        public ?int $ageSeconds = null,
        public ?int $thresholdSeconds = null,
    ) {}

    public static function ok(string $detail, ?int $ageSeconds = null, ?int $thresholdSeconds = null): self
    {
        return new self(CheckStatus::Ok, self::trim($detail), $ageSeconds, $thresholdSeconds);
    }

    public static function fail(string $detail, ?int $ageSeconds = null, ?int $thresholdSeconds = null): self
    {
        return new self(CheckStatus::Fail, self::trim($detail), $ageSeconds, $thresholdSeconds);
    }

    public static function skip(string $detail): self
    {
        return new self(CheckStatus::Skip, self::trim($detail));
    }

    private static function trim(string $detail): string
    {
        return mb_strlen($detail) > 500 ? mb_substr($detail, 0, 497).'...' : $detail;
    }
}
