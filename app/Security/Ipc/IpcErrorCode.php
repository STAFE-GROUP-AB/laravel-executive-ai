<?php

declare(strict_types=1);

namespace App\Security\Ipc;

/**
 * The §8.2 error table: one HTTP status and one fixed English sentence per
 * code, localised by the client. Messages never contain user data, paths or
 * stack traces. The three codes in `isUnsigned()` are emitted before the
 * server has a signing key (§4.5); every other response is signed.
 */
enum IpcErrorCode: string
{
    case BadRequest = 'bad_request';
    case SessionInvalid = 'session_invalid';
    case TimestampSkew = 'timestamp_skew';
    case NonceReplayed = 'nonce_replayed';
    case SignatureInvalid = 'signature_invalid';
    case OriginRejected = 'origin_rejected';
    case ForbiddenScope = 'forbidden_scope';
    case ElevationRequired = 'elevation_required';
    case ElevationInvalid = 'elevation_invalid';
    case PolicyDenied = 'policy_denied';
    case ReauthRequired = 'reauth_required';
    case NotFound = 'not_found';
    case StateConflict = 'state_conflict';
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';
    case ValidationFailed = 'validation_failed';
    case OauthStateInvalid = 'oauth_state_invalid';
    case VersionUnsupported = 'version_unsupported';
    case RateLimited = 'rate_limited';
    case Internal = 'internal';
    case SecretsUnavailable = 'secrets_unavailable';

    private const string REPAIR_HINT = 'Re-pair the menu bar with the core (Settings → Diagnostics → Reset security pairing).';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::SessionInvalid, self::TimestampSkew, self::NonceReplayed, self::SignatureInvalid => 401,
            self::OriginRejected, self::ForbiddenScope, self::ElevationRequired, self::ElevationInvalid, self::PolicyDenied, self::ReauthRequired => 403,
            self::NotFound => 404,
            self::StateConflict => 409,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::ValidationFailed, self::OauthStateInvalid => 422,
            self::VersionUnsupported => 426,
            self::RateLimited => 429,
            self::Internal => 500,
            self::SecretsUnavailable => 503,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::BadRequest => 'The request was malformed.',
            self::SessionInvalid => 'The session token is missing, malformed or expired.',
            self::TimestampSkew => 'The request timestamp is outside the accepted window.',
            self::NonceReplayed => 'The request nonce was already used or is malformed.',
            self::SignatureInvalid => 'Request signature did not verify.',
            self::OriginRejected => 'The request did not arrive from a local native client.',
            self::ForbiddenScope => 'This client is not allowed to call this operation.',
            self::ElevationRequired => 'This operation requires device-owner authentication.',
            self::ElevationInvalid => 'The elevation token did not verify.',
            self::PolicyDenied => 'The policy denied this operation.',
            self::ReauthRequired => 'This operation requires an authenticated session in the app.',
            self::NotFound => 'No such resource.',
            self::StateConflict => 'The operation cannot run from the current state.',
            self::PayloadTooLarge => 'The request body exceeds the allowed size.',
            self::UnsupportedMediaType => 'Request bodies must be application/json.',
            self::ValidationFailed => 'The request did not validate.',
            self::OauthStateInvalid => 'The OAuth state is unknown or has expired.',
            self::VersionUnsupported => 'This IPC version is not supported by the installed core.',
            self::RateLimited => 'Too many requests for this operation.',
            self::Internal => 'An unexpected error occurred.',
            self::SecretsUnavailable => 'The core could not read its IPC key.',
        };
    }

    public function hint(): ?string
    {
        return match ($this) {
            self::SessionInvalid, self::SignatureInvalid => self::REPAIR_HINT,
            self::TimestampSkew => 'Resynchronise the clock from server_time and retry once.',
            self::NonceReplayed => 'Retry once with a new nonce.',
            self::ElevationRequired, self::ElevationInvalid => 'Run elevate on the Supervisor and retry once.',
            self::ReauthRequired => 'Open the operation in the app, where the owner re-authenticates.',
            self::StateConflict => 'Re-run the remote-access inspection and show the reported state.',
            self::VersionUnsupported => 'Update or repair Laravel Executive AI (Settings → Updates → Repair).',
            self::Internal => 'Open diagnostics; the request_id is the correlation key.',
            self::SecretsUnavailable => 'The secrets broker is unavailable or locked; check the Supervisor.',
            default => null,
        };
    }

    /** §4.5: emitted before a signing key exists, therefore unsigned. */
    public function isUnsigned(): bool
    {
        return in_array($this, [self::OriginRejected, self::VersionUnsupported, self::SessionInvalid, self::SecretsUnavailable], true);
    }
}
