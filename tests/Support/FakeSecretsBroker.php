<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Runs tests/Support/fake-secrets-broker.php as a child process on a short
 * Unix socket path (sun_path holds 103 bytes; the system temp dir is long),
 * so SupervisorSecretStore and BrokerKeySource are exercised over a real
 * socket without the Supervisor or the Keychain.
 */
final class FakeSecretsBroker
{
    /** @var resource|null */
    private $process;

    private function __construct(
        public readonly string $socketPath,
        private readonly string $itemsFile,
        private readonly string $logFile,
    ) {}

    /** @param array<string, string> $items "<service>/<account>" => envelope */
    public static function start(array $items, bool $locked = false): self
    {
        $id = bin2hex(random_bytes(4));
        $directory = sys_get_temp_dir();
        $socketPath = "{$directory}/lx-{$id}.sock";

        if (strlen($socketPath) > 100) {
            $socketPath = "/private/tmp/lx-{$id}.sock";
        }

        $itemsFile = "{$directory}/lx-{$id}-items.json";
        $logFile = "{$directory}/lx-{$id}-requests.log";
        file_put_contents($logFile, '');

        $broker = new self($socketPath, $itemsFile, $logFile);
        $broker->setItems($items, $locked);

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/fake-secrets-broker.php', $socketPath, $itemsFile, $logFile],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The fake secrets broker could not be started.');
        }

        $broker->process = $process;

        $deadline = microtime(true) + 5;

        while (! file_exists($socketPath)) {
            if (microtime(true) > $deadline) {
                $broker->stop();

                throw new RuntimeException('The fake secrets broker did not open its socket.');
            }

            usleep(10_000);
        }

        return $broker;
    }

    /** @param array<string, string> $items */
    public function setItems(array $items, bool $locked = false): void
    {
        file_put_contents($this->itemsFile, json_encode(['items' => $items, 'locked' => $locked], JSON_THROW_ON_ERROR));
    }

    /** @return list<array{op: string, service: string, account: string}> */
    public function requests(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));

        return array_values(array_map(static fn (string $line): array => json_decode($line, true, 8, JSON_THROW_ON_ERROR), $lines));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }

        @unlink($this->socketPath);
        @unlink($this->itemsFile);
        @unlink($this->logFile);
    }

    public function __destruct()
    {
        $this->stop();
    }
}
