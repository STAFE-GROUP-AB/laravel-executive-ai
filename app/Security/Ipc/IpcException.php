<?php

declare(strict_types=1);

namespace App\Security\Ipc;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An expected IPC rejection (§8.2). Rendered as the error envelope by
 * `IpcResponder`; `report()` returns true so Laravel does not log every
 * rejected probe as an exception — `IpcAuditor` writes `ipc.rejected`
 * instead. The message is the fixed sentence of the code, never the
 * presented token, signature or body.
 */
final class IpcException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     * @param  list<int>|null  $supportedVersions
     */
    private function __construct(
        public readonly IpcErrorCode $errorCode,
        public readonly ?array $details = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?array $supportedVersions = null,
    ) {
        parent::__construct($errorCode->message());
    }

    public static function badRequest(?string $reason = null): self
    {
        return new self(IpcErrorCode::BadRequest, $reason === null ? null : ['reason' => $reason]);
    }

    public static function sessionInvalid(): self
    {
        return new self(IpcErrorCode::SessionInvalid);
    }

    public static function timestampSkew(): self
    {
        return new self(IpcErrorCode::TimestampSkew);
    }

    public static function nonceReplayed(): self
    {
        return new self(IpcErrorCode::NonceReplayed);
    }

    public static function signatureInvalid(): self
    {
        return new self(IpcErrorCode::SignatureInvalid);
    }

    public static function originRejected(): self
    {
        return new self(IpcErrorCode::OriginRejected);
    }

    public static function forbiddenScope(): self
    {
        return new self(IpcErrorCode::ForbiddenScope);
    }

    public static function elevationRequired(): self
    {
        return new self(IpcErrorCode::ElevationRequired);
    }

    public static function elevationInvalid(): self
    {
        return new self(IpcErrorCode::ElevationInvalid);
    }

    public static function policyDenied(): self
    {
        return new self(IpcErrorCode::PolicyDenied);
    }

    public static function reauthRequired(string $openUrl): self
    {
        return new self(IpcErrorCode::ReauthRequired, ['open_url' => $openUrl]);
    }

    public static function notFound(): self
    {
        return new self(IpcErrorCode::NotFound);
    }

    /** @param array<string, mixed>|null $details */
    public static function stateConflict(?array $details = null): self
    {
        return new self(IpcErrorCode::StateConflict, $details);
    }

    public static function payloadTooLarge(): self
    {
        return new self(IpcErrorCode::PayloadTooLarge);
    }

    public static function unsupportedMediaType(): self
    {
        return new self(IpcErrorCode::UnsupportedMediaType);
    }

    /** @param array<string, mixed> $details field => problem */
    public static function validationFailed(array $details): self
    {
        return new self(IpcErrorCode::ValidationFailed, $details === [] ? null : $details);
    }

    public static function oauthStateInvalid(): self
    {
        return new self(IpcErrorCode::OauthStateInvalid);
    }

    /** @param list<int> $supportedVersions */
    public static function versionUnsupported(array $supportedVersions): self
    {
        return new self(IpcErrorCode::VersionUnsupported, supportedVersions: $supportedVersions);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(IpcErrorCode::RateLimited, retryAfterSeconds: max(1, $retryAfterSeconds));
    }

    public static function internal(): self
    {
        return new self(IpcErrorCode::Internal);
    }

    public static function secretsUnavailable(int $retryAfterSeconds = 5): self
    {
        return new self(IpcErrorCode::SecretsUnavailable, retryAfterSeconds: max(1, $retryAfterSeconds));
    }

    public function status(): int
    {
        return $this->errorCode->status();
    }

    /** Laravel calls this when the exception escapes a controller or middleware. */
    public function render(Request $request): Response
    {
        return app(IpcResponder::class)->error($request, $this);
    }

    /** Expected rejections are audited by the middleware, not reported as exceptions. */
    public function report(): bool
    {
        return true;
    }
}
