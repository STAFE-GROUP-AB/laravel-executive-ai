<?php

declare(strict_types=1);

namespace Tests\Support\Ipc;

use App\Security\Ipc\IpcClient as Client;
use App\Security\Ipc\IpcKey;
use App\Security\Ipc\IpcSigner;
use App\Support\Json\Jcs;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;

/**
 * A menu-bar / Supervisor client for the IPC server tests: mints tokens and
 * elevations under K_test (IPC contract §4.6), signs requests per §4.2 and
 * verifies response signatures per §4.5 — the same arithmetic the Swift
 * client must implement. Every request is sent to the loopback origin the
 * fixtures use (`http://127.0.0.1:8787`), with the §4.1 header set.
 *
 * Options accepted by `send()`:
 *   client, session_id, expires_at, timestamp, nonce, signature, elevation,
 *   version, host, remote_addr, token (raw Authorization value), headers
 *   (extra or overriding request headers; a null value removes the header),
 *   raw_body (bytes to send instead of the JCS form of `$body`).
 */
final class IpcClient
{
    public const string SESSION = '01M4G0QK2T6Z5P9Y3R7W1A8C4E';

    public const string HOST = '127.0.0.1:8787';

    public const string ORIGIN = 'http://127.0.0.1:8787';

    public static function key(): IpcKey
    {
        return IpcKey::forTesting();
    }

    public static function token(string $client = 'menubar', string $sessionId = self::SESSION, ?int $expiresAt = null): string
    {
        return IpcSigner::makeToken(self::key(), Client::from($client), $sessionId, $expiresAt ?? CarbonImmutable::now('UTC')->addDays(30)->getTimestamp());
    }

    public static function signingKey(string $client = 'menubar', string $sessionId = self::SESSION): string
    {
        return IpcSigner::signingKey(self::key(), Client::from($client), $sessionId);
    }

    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function elevation(string $operation, string $nonce, ?int $expiresAt = null, string $sessionId = self::SESSION): string
    {
        return IpcSigner::makeElevation(self::key(), $sessionId, $operation, $nonce, $expiresAt ?? CarbonImmutable::now('UTC')->addSeconds(60)->getTimestamp());
    }

    /** @param array<string, mixed>|null $body */
    public static function bodyBytes(?array $body): string
    {
        return $body === null ? '' : Jcs::canonicalize($body === [] ? new \stdClass : $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $options
     */
    public static function send(string $method, string $pathAndQuery, ?array $body = null, array $options = []): TestResponse
    {
        $prepared = self::prepare($method, $pathAndQuery, $body, $options);

        return call($method, $prepared['url'], [], [], [], $prepared['server'], $prepared['content']);
    }

    /**
     * The signed request as the pieces an HTTP kernel takes, so a test can
     * send it to an application other than the suite's own (the E2E slice
     * boots a second core on a throwaway data directory).
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $options
     * @return array{url: string, server: array<string, string>, content: ?string}
     */
    public static function prepare(string $method, string $pathAndQuery, ?array $body = null, array $options = []): array
    {
        $client = (string) ($options['client'] ?? 'menubar');
        $sessionId = (string) ($options['session_id'] ?? self::SESSION);
        $timestamp = (string) ($options['timestamp'] ?? CarbonImmutable::now('UTC')->getTimestamp());
        $nonce = (string) ($options['nonce'] ?? self::nonce());
        $content = isset($options['raw_body']) ? (string) $options['raw_body'] : self::bodyBytes($body);
        $token = array_key_exists('token', $options) ? $options['token'] : self::token($client, $sessionId, $options['expires_at'] ?? null);

        $headers = [
            'Accept' => 'application/json',
            'X-Lxai-Ipc-Version' => array_key_exists('version', $options) ? $options['version'] : '1',
            'X-Lxai-Timestamp' => $timestamp,
            'X-Lxai-Nonce' => $nonce,
            'X-Lxai-Request-Id' => $options['request_id'] ?? null,
        ];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($content !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        $headers['X-Lxai-Signature'] = $options['signature']
            ?? IpcSigner::signRequest(self::signingKey($client, $sessionId), $method, $pathAndQuery, $timestamp, $nonce, $content);

        if (isset($options['elevation'])) {
            $headers['X-Lxai-Elevation'] = $options['elevation'];
        }

        foreach ($options['headers'] ?? [] as $name => $value) {
            $headers[$name] = $value;
        }

        $server = ['REMOTE_ADDR' => (string) ($options['remote_addr'] ?? '127.0.0.1')];

        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }

            $server[self::serverKey($name)] = (string) $value;
        }

        $host = (string) ($options['host'] ?? self::HOST);

        return ['url' => "http://{$host}{$pathAndQuery}", 'server' => $server, 'content' => $content === '' ? null : $content];
    }

    /** §4.5 as the client performs it: over the status, the request nonce and the body bytes received. */
    public static function responseSignatureIsValid(TestResponse $response, string $nonce, string $client = 'menubar', string $sessionId = self::SESSION): bool
    {
        $presented = $response->headers->get('X-Lxai-Signature');

        if (! is_string($presented)) {
            return false;
        }

        $expected = IpcSigner::signResponse(self::signingKey($client, $sessionId), $response->getStatusCode(), $nonce, (string) $response->getContent());

        return hash_equals($expected, $presented);
    }

    private static function serverKey(string $header): string
    {
        $key = strtoupper(str_replace('-', '_', $header));

        return in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'REMOTE_ADDR'], true) ? $key : 'HTTP_'.$key;
    }
}
