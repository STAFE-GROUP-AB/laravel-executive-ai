<?php

declare(strict_types=1);

namespace App\Security\Ipc;

/**
 * Port of packaging/fixtures/ipc-signing.mjs, the reference implementation
 * of IPC contract §3.3 (token and signing key), §4.2 (canonical request),
 * §4.5 (canonical response) and §7.3 (elevation). Pure functions, no I/O.
 * tests/Unit/Security/Ipc/IpcSignerTest.php and the fixture conformance
 * test prove byte-for-byte agreement with the recorded fixtures.
 */
final class IpcSigner
{
    public const string LF = "\n";

    public const string TOKEN_PREFIX = 'lxai1.';

    public const string ELEVATION_PREFIX = 'lxai1e.';

    public const string TOKEN_PATTERN = '/^lxai1\.(menubar|supervisor)\.([0-7][0-9A-HJKMNP-TV-Z]{25})\.([0-9]{1,12})\.([0-9a-f]{32})$/';

    public const string ELEVATION_PATTERN = '/^lxai1e\.([a-z][a-z0-9-]*)\.([A-Za-z0-9_-]{22})\.([0-9]{1,12})\.([0-9a-f]{32})$/';

    public const string NONCE_PATTERN = '/^[A-Za-z0-9_-]{22}$/';

    public const string SIGNATURE_PATTERN = '/^v1=[0-9a-f]{64}$/';

    public const string ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    public static function sha256Hex(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    private static function hmac(#[\SensitiveParameter] string $key, string $message): string
    {
        return hash_hmac('sha256', $message, $key, true);
    }

    /** §3.3: the first 128 bits of the HMAC, hex-encoded. */
    public static function tokenMac(IpcKey $key, IpcClient $client, string $sessionId, int $expiresAt): string
    {
        $message = implode(self::LF, ['lxai-ipc/v1/token', $client->value, $sessionId, (string) $expiresAt]);

        return substr(bin2hex(self::hmac($key->bytes(), $message)), 0, 32);
    }

    public static function makeToken(IpcKey $key, IpcClient $client, string $sessionId, int $expiresAt): string
    {
        return self::TOKEN_PREFIX.$client->value.'.'.$sessionId.'.'.$expiresAt.'.'.self::tokenMac($key, $client, $sessionId, $expiresAt);
    }

    /** §3.3: 32 raw bytes, never transmitted. */
    public static function signingKey(IpcKey $key, IpcClient $client, string $sessionId): string
    {
        return self::hmac($key->bytes(), implode(self::LF, ['lxai-ipc/v1/signing-key', $client->value, $sessionId]));
    }

    /** §4.2: `bodyBytes` exactly as received; the empty string when there is no body. */
    public static function canonicalRequest(string $method, string $pathAndQuery, string $timestamp, string $nonce, string $bodyBytes): string
    {
        return implode(self::LF, ['LXAI-IPC-REQ/1', $method, $pathAndQuery, $timestamp, $nonce, self::sha256Hex($bodyBytes)]);
    }

    public static function signRequest(#[\SensitiveParameter] string $signingKey, string $method, string $pathAndQuery, string $timestamp, string $nonce, string $bodyBytes): string
    {
        return 'v1='.bin2hex(self::hmac($signingKey, self::canonicalRequest($method, $pathAndQuery, $timestamp, $nonce, $bodyBytes)));
    }

    /** §4.5 */
    public static function canonicalResponse(int $status, string $requestNonce, string $bodyBytes): string
    {
        return implode(self::LF, ['LXAI-IPC-RES/1', (string) $status, $requestNonce, self::sha256Hex($bodyBytes)]);
    }

    public static function signResponse(#[\SensitiveParameter] string $signingKey, int $status, string $requestNonce, string $bodyBytes): string
    {
        return 'v1='.bin2hex(self::hmac($signingKey, self::canonicalResponse($status, $requestNonce, $bodyBytes)));
    }

    /** §7.3 */
    public static function elevationMac(IpcKey $key, string $sessionId, string $operation, string $nonce, int $expiresAt): string
    {
        $message = implode(self::LF, ['lxai-ipc/v1/elevation', $sessionId, $operation, $nonce, (string) $expiresAt]);

        return substr(bin2hex(self::hmac($key->bytes(), $message)), 0, 32);
    }

    public static function makeElevation(IpcKey $key, string $sessionId, string $operation, string $nonce, int $expiresAt): string
    {
        return self::ELEVATION_PREFIX.$operation.'.'.$nonce.'.'.$expiresAt.'.'.self::elevationMac($key, $sessionId, $operation, $nonce, $expiresAt);
    }

    public static function constantTimeEquals(string $expected, string $presented): bool
    {
        return hash_equals($expected, $presented);
    }
}
