<?php

declare(strict_types=1);

namespace App\Security\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * The `tap` listed on every channel in config/logging.php. It pushes
 * RedactSecretsProcessor onto the channel's Monolog logger so the processor
 * runs ahead of every handler. LoggingConfigTest fails the build when a
 * channel lacks it (threat model T-75).
 */
final class RedactSecretsTap
{
    public function __construct(private readonly RedactSecretsProcessor $processor) {}

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor($this->processor);
        }
    }
}
