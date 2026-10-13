<?php

declare(strict_types=1);

namespace App\AI\Providers\Detection;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The one HTTP client the detectors use: loopback only, with ADR-004 D3's
 * budget of 500 ms to connect and 2 s in total, no redirects and no
 * credentials.
 *
 * The literal `127.0.0.1` is the only host it will dial. That is not a
 * convenience: a detector that accepted a hostname would be a DNS-rebinding
 * path straight through the Local Only boundary, so the restriction lives in
 * the probe rather than in each caller.
 */
final readonly class LoopbackProbe
{
    public const string HOST = '127.0.0.1';

    public const float CONNECT_TIMEOUT_SECONDS = 0.5;

    public const int TOTAL_TIMEOUT_SECONDS = 2;

    public function __construct(private Factory $http) {}

    public function baseUrl(int $port): string
    {
        return 'http://'.self::HOST.':'.$port;
    }

    /**
     * GET a loopback path, or null when nothing answered. A non-2xx answer is
     * still returned, because `401` means "running, token required" and the
     * caller must be able to tell that from silence.
     *
     * @param  array<string, string>  $headers
     */
    public function get(int $port, string $path, array $headers = []): ?Response
    {
        try {
            return $this->http
                ->withHeaders($headers)
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TOTAL_TIMEOUT_SECONDS)
                ->accept('application/json')
                ->get($this->baseUrl($port).'/'.ltrim($path, '/'));
        } catch (ConnectionException) {
            return null;
        } catch (Throwable) {
            // Detection may never fail the install (§12.1): anything the client
            // throws is reported as "not found", not escalated.
            return null;
        }
    }

    /**
     * POST a loopback path with a JSON body; same budget and same silence rule.
     *
     * @param  array<string, mixed>  $body
     */
    public function post(int $port, string $path, array $body = []): ?Response
    {
        try {
            return $this->http
                ->withoutRedirecting()
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TOTAL_TIMEOUT_SECONDS)
                ->acceptJson()
                ->post($this->baseUrl($port).'/'.ltrim($path, '/'), $body);
        } catch (ConnectionException) {
            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
