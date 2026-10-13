<?php

declare(strict_types=1);

namespace App\AI\Providers\LaravelAi;

use App\AI\Providers\Dto\AiError;
use App\AI\Providers\Dto\AiErrorKind;
use App\AI\Providers\Exceptions\AiProviderException;
use App\AI\Providers\Exceptions\ProviderUnavailableException;
use App\Security\Secrets\Redactor;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\NoSuchToolException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Throwable;

/**
 * SDK exception → our `AiError` taxonomy (ADR-004 D2). The six classes the SDK
 * defines map one-to-one; everything else is `unknown`.
 *
 * Two rules make this more than a `match`:
 *
 * - **Nothing vendor-written reaches our message.** A provider's error body can
 *   quote the prompt back, and the prompt can contain the owner's mail. So the
 *   message on an `AiError` is our own sentence, the vendor's text goes through
 *   `Redactor` before it is logged, and the original exception is attached as
 *   `previous` for the stack trace only.
 * - **An unknown exception is logged scrubbed and still raised.** Row 7 of the
 *   contract matrix asserts both halves: the taxonomy is complete for the
 *   classes the SDK ships, and an unrecognised one does not quietly become a
 *   retryable failure.
 */
final readonly class ErrorMapper
{
    public function __construct(private Redactor $redactor) {}

    public function map(Throwable $exception): AiError
    {
        return match (true) {
            $exception instanceof ProviderConnectionException => new AiError(AiErrorKind::Connection, 'The model provider could not be reached.', retryable: true),
            $exception instanceof ProviderOverloadedException => new AiError(AiErrorKind::Overloaded, 'The model provider is overloaded.', retryable: true),
            $exception instanceof RateLimitedException => new AiError(AiErrorKind::RateLimited, 'The model provider rate-limited this account.', retryable: true),
            $exception instanceof InsufficientCreditsException => new AiError(AiErrorKind::InsufficientCredits, 'The model provider reports no remaining credit or quota.', retryable: false),
            $exception instanceof StreamErrorException => new AiError(AiErrorKind::StreamError, 'The provider ended the stream before the step completed.', retryable: true),
            $exception instanceof NoSuchToolException => new AiError(AiErrorKind::NoSuchTool, 'The model proposed a tool that is not offered in this run.', retryable: false),
            $exception instanceof RequestException => new AiError(AiErrorKind::Connection, 'The model provider returned an error response.', retryable: true),
            default => $this->unknown($exception),
        };
    }

    /**
     * Wrap a mapped failure as the exception our contract raises. Only the
     * retryable provider failures become `ProviderUnavailableException`; the
     * caller may retry **the same** provider when D7's circuit breaker allows,
     * and may never substitute another (D2 "no failover").
     */
    public function toException(Throwable $exception): AiProviderException
    {
        if ($exception instanceof AiProviderException) {
            return $exception;
        }

        return new ProviderUnavailableException($this->map($exception));
    }

    private function unknown(Throwable $exception): AiError
    {
        Log::warning('An unmapped AI provider exception was raised.', [
            'exception' => $this->redactor->describeThrowable($exception),
        ]);

        return new AiError(
            kind: AiErrorKind::Unknown,
            message: 'The model provider failed for an unrecognised reason.',
            retryable: false,
        );
    }
}
