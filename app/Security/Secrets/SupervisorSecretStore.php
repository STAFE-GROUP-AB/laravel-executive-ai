<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use JsonException;

/**
 * The production driver (ADR-003 D3): one length-prefixed JSON frame per
 * connection over the Supervisor's Unix socket (LEA_SECRETS_SOCKET). The
 * Supervisor verifies the peer before reading a byte, so there is no token
 * here — the process identity is the credential. Every call is a fresh
 * connection and a fresh Keychain read on the other side; nothing is cached.
 *
 * Frame: 4-byte big-endian length, then UTF-8 JSON.
 *   request  {"v":1,"op":"get|put|delete|list|health","service":…,"account":…,"value":…}
 *   response {"v":1,"ok":true,"value":…} | {"v":1,"ok":true,"accounts":[…]} | {"v":1,"ok":false,"error":"<code>"}
 */
final class SupervisorSecretStore implements SecretStore
{
    private const int MAX_FRAME_BYTES = 64 * 1024;

    public function __construct(
        private readonly string $socketPath,
        private readonly int $timeoutMs = 2000,
        private readonly ?Redactor $redactor = null,
    ) {}

    public function get(SecretRef $ref): SecretValue
    {
        $response = $this->exchange(['v' => 1, 'op' => 'get', 'service' => $ref->service, 'account' => $ref->account]);
        $value = $response['value'] ?? null;

        if (! is_string($value) || $value === '') {
            throw new SecretUnavailableException("not_found: {$ref}");
        }

        $this->redactor?->register($value);

        return new SecretValue($value);
    }

    public function put(SecretRef $ref, #[\SensitiveParameter] string $envelope): void
    {
        $this->exchange(['v' => 1, 'op' => 'put', 'service' => $ref->service, 'account' => $ref->account, 'value' => $envelope]);
    }

    public function delete(SecretRef $ref): void
    {
        $this->exchange(['v' => 1, 'op' => 'delete', 'service' => $ref->service, 'account' => $ref->account]);
    }

    public function list(string $service): array
    {
        $response = $this->exchange(['v' => 1, 'op' => 'list', 'service' => $service]);
        $accounts = $response['accounts'] ?? [];

        if (! is_array($accounts)) {
            throw new SecretUnavailableException('protocol: list returned no accounts.');
        }

        return array_values(array_filter($accounts, 'is_string'));
    }

    /**
     * The broker's own health: reachable, and whether it is locked (ADR-003 D3.2).
     *
     * @return array{locked: bool}
     */
    public function health(): array
    {
        $response = $this->exchange(['v' => 1, 'op' => 'health']);

        return ['locked' => (bool) ($response['locked'] ?? false)];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function exchange(array $request): array
    {
        $socket = @stream_socket_client(
            'unix://'.$this->socketPath,
            $errorCode,
            $errorMessage,
            max(1, intdiv($this->timeoutMs, 1000)),
            STREAM_CLIENT_CONNECT,
        );

        if ($socket === false) {
            throw new SecretUnavailableException('unavailable: the secrets broker socket cannot be reached.');
        }

        try {
            stream_set_timeout($socket, intdiv($this->timeoutMs, 1000), ($this->timeoutMs % 1000) * 1000);

            $this->writeFrame($socket, $request);
            $response = $this->readFrame($socket);
        } finally {
            fclose($socket);
        }

        if (($response['ok'] ?? false) !== true) {
            $code = $response['error'] ?? 'protocol';

            throw new SecretUnavailableException(is_string($code) ? $code : 'protocol');
        }

        return $response;
    }

    /**
     * @param  resource  $socket
     * @param  array<string, mixed>  $request
     */
    private function writeFrame($socket, array $request): void
    {
        try {
            $json = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new SecretUnavailableException('protocol: the request could not be encoded.', previous: $exception);
        }

        $frame = pack('N', strlen($json)).$json;
        $offset = 0;

        while ($offset < strlen($frame)) {
            $written = @fwrite($socket, substr($frame, $offset));

            if ($written === false || $written === 0) {
                throw new SecretUnavailableException('unavailable: the secrets broker closed the connection.');
            }

            $offset += $written;
        }
    }

    /**
     * @param  resource  $socket
     * @return array<string, mixed>
     */
    private function readFrame($socket): array
    {
        $prefix = $this->readExactly($socket, 4);
        $length = unpack('N', $prefix)[1] ?? 0;

        if (! is_int($length) || $length <= 0 || $length > self::MAX_FRAME_BYTES) {
            throw new SecretUnavailableException('protocol: malformed frame length.');
        }

        try {
            $decoded = json_decode($this->readExactly($socket, $length), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SecretUnavailableException('protocol: the response is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded) || ($decoded['v'] ?? null) !== 1) {
            throw new SecretUnavailableException('protocol: unexpected response version.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param resource $socket */
    private function readExactly($socket, int $count): string
    {
        $buffer = '';

        while (strlen($buffer) < $count) {
            $chunk = @fread($socket, $count - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                throw new SecretUnavailableException('unavailable: the secrets broker closed the connection.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
