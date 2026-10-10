<?php

declare(strict_types=1);

/*
 * A stand-in for the Supervisor's secrets broker (ADR-003 D3.1 protocol), run as a child
 * process by Tests\Support\FakeSecretsBroker. Serves the items in the JSON file given as the
 * second argument from a Unix socket at the first argument, appends one line per request to
 * the log file given as the third argument, and keeps running until it is killed.
 *
 *   php fake-secrets-broker.php <socket path> <items.json> <requests.log>
 *
 * items.json: {"items": {"<service>/<account>": "<envelope string>"}, "locked": false}
 * The file is re-read on every request so a test can rotate a key by rewriting it.
 */

[$script, $socketPath, $itemsFile, $logFile] = array_pad($argv, 4, null);

if (! is_string($socketPath) || ! is_string($itemsFile) || ! is_string($logFile)) {
    fwrite(STDERR, "usage: {$script} <socket> <items.json> <requests.log>\n");
    exit(64);
}

@unlink($socketPath);

$server = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);

if ($server === false) {
    fwrite(STDERR, "cannot listen on {$socketPath}: {$errorMessage}\n");
    exit(69);
}

/** @return array<string, mixed> */
$state = static function () use ($itemsFile): array {
    $decoded = json_decode((string) file_get_contents($itemsFile), true);

    return is_array($decoded) ? $decoded : [];
};

$frame = static function (array $payload): string {
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return pack('N', strlen($json)).$json;
};

while (($client = @stream_socket_accept($server, 30)) !== false) {
    $prefix = fread($client, 4);

    if (! is_string($prefix) || strlen($prefix) !== 4) {
        fclose($client);

        continue;
    }

    $length = (int) (unpack('N', $prefix)[1] ?? 0);
    $body = '';

    while ($length > 0 && strlen($body) < $length) {
        $chunk = fread($client, $length - strlen($body));

        if (! is_string($chunk) || $chunk === '') {
            break;
        }

        $body .= $chunk;
    }

    $request = json_decode($body, true);
    $current = $state();
    $items = is_array($current['items'] ?? null) ? $current['items'] : [];
    $locked = (bool) ($current['locked'] ?? false);

    $op = is_array($request) ? ($request['op'] ?? '?') : '?';
    $service = is_array($request) ? ($request['service'] ?? '-') : '-';
    $account = is_array($request) ? ($request['account'] ?? '-') : '-';
    file_put_contents($logFile, json_encode(['op' => $op, 'service' => $service, 'account' => $account], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

    $response = match (true) {
        ! is_array($request) || ($request['v'] ?? null) !== 1 => ['v' => 1, 'ok' => false, 'error' => 'bad_request'],
        $op === 'health' => ['v' => 1, 'ok' => true, 'locked' => $locked],
        ! is_string($service) || ! str_starts_with($service, 'se.stafe.executive-ai.') => ['v' => 1, 'ok' => false, 'error' => 'forbidden'],
        $op === 'list' => ['v' => 1, 'ok' => true, 'accounts' => array_values(array_map(
            static fn (string $key): string => substr($key, strlen($service) + 1),
            array_filter(array_keys($items), static fn (string $key): bool => str_starts_with($key, $service.'/')),
        ))],
        $op === 'get' && $locked => ['v' => 1, 'ok' => false, 'error' => 'locked'],
        $op === 'get' => isset($items["{$service}/{$account}"])
            ? ['v' => 1, 'ok' => true, 'value' => $items["{$service}/{$account}"]]
            : ['v' => 1, 'ok' => false, 'error' => 'not_found'],
        $op === 'put' || $op === 'delete' => ['v' => 1, 'ok' => true],
        default => ['v' => 1, 'ok' => false, 'error' => 'bad_request'],
    };

    fwrite($client, $frame($response));
    fclose($client);
}
