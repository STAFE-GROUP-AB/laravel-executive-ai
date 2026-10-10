<?php

declare(strict_types=1);

namespace App\Security\Logging;

use App\Security\Secrets\Redactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that runs every record's message, context and extra
 * through the Redactor before any handler formats it (ADR-003 D7 row 1,
 * threat model T-10). Registered on every channel by RedactSecretsTap.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public function __construct(private readonly Redactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redactString($record->message),
            context: $this->redactor->redactArray($record->context),
            extra: $this->redactor->redactArray($record->extra),
        );
    }
}
