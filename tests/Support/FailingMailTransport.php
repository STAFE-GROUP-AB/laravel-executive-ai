<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that fails loudly if anything reaches it. Registered as
 * the default mailer by the preview tests (blueprint §1.3, §5.5): a preview
 * path that touched the transport would throw, and the attempt counter
 * records it even if the exception were swallowed.
 */
final class FailingMailTransport implements TransportInterface
{
    public static int $attempts = 0;

    public static function reset(): void
    {
        self::$attempts = 0;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        self::$attempts++;

        throw new RuntimeException('FailingMailTransport::send() was reached: the preview path dispatched mail.');
    }

    public function __toString(): string
    {
        return 'failing://';
    }
}
