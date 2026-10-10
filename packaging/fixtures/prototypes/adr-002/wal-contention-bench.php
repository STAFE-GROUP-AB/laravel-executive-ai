#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * ADR-002 evidence: SQLite WAL write-contention benchmark (OPEN-QUESTIONS A9).
 *
 * Creates a WAL-mode database with an `email_messages`-shaped table, then spawns
 * N concurrent writer processes (proc_open) that insert a fixed total number of
 * rows in batched transactions while one reader process runs a query loop.
 * Measures wall time, inserts/s, SQLITE_BUSY errors, p95 transaction latency and
 * p95 write-lock wait, for several writer counts and for synchronous=NORMAL|FULL.
 *
 * Usage (parent):
 *   php wal-contention-bench.php [--writers=1,2,4,8,12] [--rows=100000] [--batch=500]
 *       [--sync=NORMAL,FULL] [--busy-timeout=5000] [--fts5=1] [--fullfsync=1]
 *       [--skip-backup-probe=1] [--db-dir=./tmp] [--out=results/<date>.json]
 *
 * Child modes (spawned by the parent, not meant to be run by hand):
 *   --child=writer --db=<path> --id=<n> --rows=<n> --batch=<n> --sync=<mode> --busy-timeout=<ms>
 *   --child=reader --db=<path> --stop-file=<path> --busy-timeout=<ms>
 *
 * Prototype only: plain PDO, no framework, no app code. Never run against a real database.
 */

const SCHEMA = <<<'SQL'
CREATE TABLE email_messages (
    id TEXT PRIMARY KEY NOT NULL,
    connection_id TEXT NOT NULL,
    provider_message_id TEXT NOT NULL,
    thread_id TEXT NULL,
    subject TEXT NOT NULL,
    body_preview TEXT NULL,
    received_at TEXT NOT NULL,
    meta TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX email_messages_connection_provider_unique
    ON email_messages (connection_id, provider_message_id);
CREATE INDEX email_messages_thread_id_index ON email_messages (thread_id);
CREATE INDEX email_messages_connection_received_index ON email_messages (connection_id, received_at);
SQL;

// Optional (--fts5=1): external-content FTS5 index kept in sync by triggers, the §3.2 shape.
const SCHEMA_FTS5 = <<<'SQL'
CREATE VIRTUAL TABLE email_messages_fts USING fts5(subject, body_preview, content='email_messages', content_rowid='rowid', tokenize='unicode61');
CREATE TRIGGER email_messages_ai AFTER INSERT ON email_messages BEGIN
    INSERT INTO email_messages_fts(rowid, subject, body_preview) VALUES (new.rowid, new.subject, new.body_preview);
END;
CREATE TRIGGER email_messages_ad AFTER DELETE ON email_messages BEGIN
    INSERT INTO email_messages_fts(email_messages_fts, rowid, subject, body_preview) VALUES ('delete', old.rowid, old.subject, old.body_preview);
END;
CREATE TRIGGER email_messages_au AFTER UPDATE ON email_messages BEGIN
    INSERT INTO email_messages_fts(email_messages_fts, rowid, subject, body_preview) VALUES ('delete', old.rowid, old.subject, old.body_preview);
    INSERT INTO email_messages_fts(rowid, subject, body_preview) VALUES (new.rowid, new.subject, new.body_preview);
END;
SQL;

function parseArgs(array $argv): array
{
    $args = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $args[$key] = $value;
    }

    return $args;
}

function out(string $line): void
{
    fwrite(STDOUT, $line.PHP_EOL);
}

function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0.0;
    }

    sort($values);
    $index = (int) ceil(($p / 100) * count($values)) - 1;

    return (float) $values[max(0, min($index, count($values) - 1))];
}

function ulid(): string
{
    static $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $time = (int) floor(microtime(true) * 1000);
    $out = '';

    for ($i = 9; $i >= 0; $i--) {
        $out = $alphabet[$time % 32].$out;
        $time = intdiv($time, 32);
    }

    for ($i = 0; $i < 16; $i++) {
        $out .= $alphabet[random_int(0, 31)];
    }

    return $out;
}

function openDb(string $path, string $sync, int $busyTimeoutMs, bool $fullfsync = false): PDO
{
    $pdo = new PDO("sqlite:{$path}", null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => (int) ceil($busyTimeoutMs / 1000),
    ]);

    $pdo->exec("PRAGMA busy_timeout = {$busyTimeoutMs}");
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("PRAGMA synchronous = {$sync}");
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA temp_store = MEMORY');

    // macOS only: plain fsync() does not flush the drive cache; F_FULLFSYNC does (and costs).
    $pdo->exec('PRAGMA fullfsync = '.($fullfsync ? 'ON' : 'OFF'));

    return $pdo;
}

function isBusy(PDOException $e): bool
{
    $code = $e->errorInfo[1] ?? null;

    // 5 = SQLITE_BUSY, 6 = SQLITE_LOCKED (incl. extended codes in the low byte).
    return $code !== null && (($code & 0xff) === 5 || ($code & 0xff) === 6)
        || str_contains($e->getMessage(), 'database is locked');
}

function runWriter(array $args): void
{
    $db = $args['db'];
    $id = (int) $args['id'];
    $rows = (int) $args['rows'];
    $batch = (int) $args['batch'];
    $sync = $args['sync'];
    $busyTimeoutMs = (int) $args['busy-timeout'];

    $pdo = openDb($db, $sync, $busyTimeoutMs, ($args['fullfsync'] ?? '0') === '1');
    $insert = $pdo->prepare(
        'INSERT INTO email_messages (id, connection_id, provider_message_id, thread_id, subject, body_preview, received_at, meta, created_at, updated_at)
         VALUES (:id, :connection_id, :provider_message_id, :thread_id, :subject, :body_preview, :received_at, :meta, :now, :now)'
    );

    $connectionId = sprintf('01J%023s', strtoupper(base_convert((string) $id, 10, 32)));
    $lorem = str_repeat('Quarterly forecast review and follow-up on the signed proposal. ', 4);
    $txLatencies = [];
    $lockWaits = [];
    $busyErrors = 0;
    $inserted = 0;
    $started = hrtime(true);

    while ($inserted < $rows) {
        $size = min($batch, $rows - $inserted);
        $txStart = hrtime(true);

        try {
            $lockStart = hrtime(true);
            $pdo->exec('BEGIN IMMEDIATE');
            $lockWaits[] = (hrtime(true) - $lockStart) / 1e6;

            for ($i = 0; $i < $size; $i++) {
                $n = $inserted + $i;
                $now = gmdate('Y-m-d H:i:s');
                $insert->execute([
                    ':id' => ulid(),
                    ':connection_id' => $connectionId,
                    ':provider_message_id' => "msg-{$id}-{$n}-".bin2hex(random_bytes(6)),
                    ':thread_id' => sprintf('thr-%d-%d', $id, intdiv($n, 7)),
                    ':subject' => sprintf('Re: Proposal %d for account %d — next steps and timeline', $n, $id),
                    ':body_preview' => substr($lorem, 0, 220),
                    ':received_at' => gmdate('Y-m-d H:i:s', 1_700_000_000 + $n * 37 + $id),
                    ':meta' => json_encode([
                        'from' => "sender{$n}@example-{$id}.test",
                        'to' => ["owner@example.test"],
                        'labels' => ['INBOX', 'IMPORTANT'],
                        'size' => 12_000 + $n % 9_000,
                        'has_attachments' => $n % 11 === 0,
                    ], JSON_THROW_ON_ERROR),
                    ':now' => $now,
                ]);
            }

            $pdo->exec('COMMIT');
            $inserted += $size;
            $txLatencies[] = (hrtime(true) - $txStart) / 1e6;
        } catch (PDOException $e) {
            if (! isBusy($e)) {
                throw $e;
            }

            $busyErrors++;

            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }

            usleep(random_int(1_000, 20_000));
        }
    }

    out(json_encode([
        'writer' => $id,
        'rows' => $inserted,
        'transactions' => count($txLatencies),
        'busy_errors' => $busyErrors,
        'elapsed_ms' => round((hrtime(true) - $started) / 1e6, 1),
        'tx_ms' => [
            'p50' => round(percentile($txLatencies, 50), 2),
            'p95' => round(percentile($txLatencies, 95), 2),
            'p99' => round(percentile($txLatencies, 99), 2),
            'max' => round($txLatencies === [] ? 0 : max($txLatencies), 2),
        ],
        'lock_wait_ms' => [
            'p50' => round(percentile($lockWaits, 50), 2),
            'p95' => round(percentile($lockWaits, 95), 2),
            'max' => round($lockWaits === [] ? 0 : max($lockWaits), 2),
            'total' => round(array_sum($lockWaits), 1),
        ],
        'tx_latencies_ms' => array_map(fn ($v) => round($v, 2), $txLatencies),
    ], JSON_THROW_ON_ERROR));
}

function runReader(array $args): void
{
    $pdo = openDb($args['db'], 'NORMAL', (int) $args['busy-timeout']);
    $stopFile = $args['stop-file'];
    $latencies = [];
    $busyErrors = 0;
    $queries = 0;
    $maxRowsSeen = 0;
    $started = hrtime(true);

    $recent = $pdo->prepare('SELECT id, subject, received_at FROM email_messages WHERE connection_id = :c ORDER BY received_at DESC LIMIT 20');
    $count = $pdo->prepare('SELECT connection_id, COUNT(*) FROM email_messages GROUP BY connection_id');
    $thread = $pdo->prepare('SELECT COUNT(*) FROM email_messages WHERE thread_id = :t');

    while (! file_exists($stopFile)) {
        $t = hrtime(true);

        try {
            $recent->execute([':c' => sprintf('01J%023s', strtoupper(base_convert((string) random_int(1, 12), 10, 32)))]);
            $recent->fetchAll();
            $count->execute();
            $maxRowsSeen = max($maxRowsSeen, (int) array_sum(array_column($count->fetchAll(PDO::FETCH_NUM), 1)));
            $thread->execute([':t' => sprintf('thr-%d-%d', random_int(1, 12), random_int(0, 2000))]);
            $thread->fetchColumn();
            $queries += 3;
        } catch (PDOException $e) {
            if (! isBusy($e)) {
                throw $e;
            }

            $busyErrors++;
        }

        $latencies[] = (hrtime(true) - $t) / 1e6;
        usleep(2_000);
    }

    out(json_encode([
        'reader' => true,
        'queries' => $queries,
        'busy_errors' => $busyErrors,
        'elapsed_ms' => round((hrtime(true) - $started) / 1e6, 1),
        'max_rows_seen' => $maxRowsSeen,
        'loop_ms' => [
            'p50' => round(percentile($latencies, 50), 2),
            'p95' => round(percentile($latencies, 95), 2),
            'max' => round($latencies === [] ? 0 : max($latencies), 2),
        ],
    ], JSON_THROW_ON_ERROR));
}

function spawn(array $argv): array
{
    $cmd = array_merge([PHP_BINARY, __FILE__], $argv);
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($proc)) {
        throw new RuntimeException('proc_open failed');
    }

    return ['proc' => $proc, 'pipes' => $pipes];
}

function collect(array $child): array
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $exit = proc_close($child['proc']);

    if ($exit !== 0) {
        throw new RuntimeException("child exited {$exit}: {$stderr}");
    }

    return json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
}

function freshDb(string $path): void
{
    foreach ([$path, "{$path}-wal", "{$path}-shm"] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

function runScenario(string $dbDir, int $writers, int $totalRows, int $batch, string $sync, int $busyTimeoutMs, bool $fts5, bool $fullfsync): array
{
    $variant = ($fts5 ? '-fts5' : '').($fullfsync ? '-fullfsync' : '');
    $db = "{$dbDir}/bench-{$sync}-w{$writers}{$variant}.sqlite";
    $stopFile = "{$db}.stop";
    freshDb($db);
    @unlink($stopFile);

    $setup = openDb($db, $sync, $busyTimeoutMs);
    $setup->exec(SCHEMA);

    if ($fts5) {
        $setup->exec(SCHEMA_FTS5);
    }

    $setup = null;

    $perWriter = intdiv($totalRows, $writers);
    $remainder = $totalRows % $writers;

    out(sprintf('  synchronous=%s writers=%d rows=%d batch=%d fts5=%s fullfsync=%s ...', $sync, $writers, $totalRows, $batch, $fts5 ? 'on' : 'off', $fullfsync ? 'on' : 'off'));

    $reader = spawn(['--child=reader', "--db={$db}", "--stop-file={$stopFile}", "--busy-timeout={$busyTimeoutMs}"]);
    usleep(50_000);

    $wallStart = hrtime(true);
    $children = [];

    for ($i = 1; $i <= $writers; $i++) {
        $rows = $perWriter + ($i <= $remainder ? 1 : 0);
        $children[] = spawn(['--child=writer', "--db={$db}", "--id={$i}", "--rows={$rows}", "--batch={$batch}", "--sync={$sync}", "--busy-timeout={$busyTimeoutMs}", '--fullfsync='.($fullfsync ? '1' : '0')]);
    }

    $writerResults = array_map(collect(...), $children);
    $wallMs = (hrtime(true) - $wallStart) / 1e6;

    touch($stopFile);
    $readerResult = collect($reader);
    @unlink($stopFile);

    $verify = openDb($db, $sync, $busyTimeoutMs);
    $rowCount = (int) $verify->query('SELECT COUNT(*) FROM email_messages')->fetchColumn();
    $distinct = (int) $verify->query('SELECT COUNT(*) FROM (SELECT DISTINCT connection_id, provider_message_id FROM email_messages)')->fetchColumn();
    $walBytes = file_exists("{$db}-wal") ? filesize("{$db}-wal") : 0;
    $checkpointStart = hrtime(true);
    $checkpoint = $verify->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch(PDO::FETCH_NUM);
    $checkpointMs = (hrtime(true) - $checkpointStart) / 1e6;
    $dbBytes = filesize($db);
    $verify = null;

    $allTx = array_merge(...array_map(fn ($w) => $w['tx_latencies_ms'], $writerResults));
    $allLockWaitP95 = max(array_map(fn ($w) => $w['lock_wait_ms']['p95'], $writerResults));
    $allLockWaitMax = max(array_map(fn ($w) => $w['lock_wait_ms']['max'], $writerResults));

    $ftsMatches = null;

    if ($fts5) {
        $fts = openDb($db, $sync, $busyTimeoutMs);
        $t = hrtime(true);
        $ftsMatches = (int) $fts->query("SELECT COUNT(*) FROM email_messages_fts WHERE email_messages_fts MATCH 'proposal AND timeline'")->fetchColumn();
        $ftsMatchMs = (hrtime(true) - $t) / 1e6;
        $fts = null;
    }

    $result = [
        'synchronous' => $sync,
        'fts5' => $fts5,
        'fullfsync' => $fullfsync,
        'fts5_match_probe' => $fts5 ? ['matches' => $ftsMatches, 'ms' => round($ftsMatchMs, 1)] : null,
        'writers' => $writers,
        'total_rows' => $totalRows,
        'batch' => $batch,
        'busy_timeout_ms' => $busyTimeoutMs,
        'wall_ms' => round($wallMs, 1),
        'inserts_per_sec' => (int) round($totalRows / ($wallMs / 1000)),
        'transactions' => count($allTx),
        'busy_errors_writers' => array_sum(array_column($writerResults, 'busy_errors')),
        'busy_errors_reader' => $readerResult['busy_errors'],
        'tx_ms' => [
            'p50' => round(percentile($allTx, 50), 2),
            'p95' => round(percentile($allTx, 95), 2),
            'p99' => round(percentile($allTx, 99), 2),
            'max' => round($allTx === [] ? 0 : max($allTx), 2),
        ],
        'lock_wait_ms' => ['p95' => $allLockWaitP95, 'max' => $allLockWaitMax],
        'reader' => [
            'queries' => $readerResult['queries'],
            'queries_per_sec' => (int) round($readerResult['queries'] / max(0.001, $readerResult['elapsed_ms'] / 1000)),
            'loop_ms' => $readerResult['loop_ms'],
        ],
        'verify' => [
            'row_count' => $rowCount,
            'distinct_connection_provider' => $distinct,
            'ok' => $rowCount === $totalRows && $distinct === $totalRows,
        ],
        'wal_bytes_before_checkpoint' => $walBytes,
        'checkpoint_truncate' => ['ms' => round($checkpointMs, 1), 'result' => $checkpoint],
        'db_bytes' => $dbBytes,
        'per_writer' => array_map(fn ($w) => array_diff_key($w, ['tx_latencies_ms' => 1]), $writerResults),
    ];

    out(sprintf(
        '    wall %.1fs · %d inserts/s · busy(writers)=%d busy(reader)=%d · tx p50/p95/p99 %.1f/%.1f/%.1f ms · lock-wait p95 %.1f ms · reader %d q/s · verify %s',
        $wallMs / 1000,
        $result['inserts_per_sec'],
        $result['busy_errors_writers'],
        $result['busy_errors_reader'],
        $result['tx_ms']['p50'],
        $result['tx_ms']['p95'],
        $result['tx_ms']['p99'],
        $allLockWaitP95,
        $result['reader']['queries_per_sec'],
        $result['verify']['ok'] ? 'ok' : 'FAILED'
    ));

    return $result;
}

function runBackupProbe(string $dbDir, int $rows): array
{
    $db = "{$dbDir}/backup-probe.sqlite";
    freshDb($db);
    $pdo = openDb($db, 'NORMAL', 5000);
    $pdo->exec(SCHEMA);
    $pdo->exec('BEGIN');
    $stmt = $pdo->prepare('INSERT INTO email_messages VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    for ($i = 0; $i < $rows; $i++) {
        $stmt->execute([ulid(), 'conn', "m{$i}", 'thr'.intdiv($i, 7), "Subject {$i}", str_repeat('x', 220), '2026-10-10 00:00:00', '{"a":1}', 'now', 'now']);
    }

    $pdo->exec('COMMIT');
    $pdo = null;

    $vacuumTarget = "{$dbDir}/backup-probe.vacuum-into.sqlite";
    @unlink($vacuumTarget);
    $src = new SQLite3($db, SQLITE3_OPEN_READONLY);
    $src->busyTimeout(5000);
    $t = hrtime(true);
    $src->exec("VACUUM INTO '{$vacuumTarget}'");
    $vacuumMs = (hrtime(true) - $t) / 1e6;

    $apiTarget = "{$dbDir}/backup-probe.backup-api.sqlite";
    @unlink($apiTarget);
    $dst = new SQLite3($apiTarget);
    $t = hrtime(true);
    $ok = $src->backup($dst);
    $apiMs = (hrtime(true) - $t) / 1e6;
    $dst->close();
    $src->close();

    $check = fn (string $p) => (new PDO("sqlite:{$p}"))->query('PRAGMA integrity_check')->fetchColumn();

    return [
        'rows' => $rows,
        'source_bytes' => filesize($db),
        'vacuum_into' => ['ms' => round($vacuumMs, 1), 'bytes' => filesize($vacuumTarget), 'integrity' => $check($vacuumTarget)],
        'backup_api' => ['ok' => $ok, 'ms' => round($apiMs, 1), 'bytes' => filesize($apiTarget), 'integrity' => $check($apiTarget)],
    ];
}

function runParent(array $args): void
{
    $writerCounts = array_map('intval', explode(',', $args['writers'] ?? '1,2,4,8,12'));
    $syncModes = array_map('strtoupper', explode(',', $args['sync'] ?? 'NORMAL,FULL'));
    $totalRows = (int) ($args['rows'] ?? 100_000);
    $batch = (int) ($args['batch'] ?? 500);
    $busyTimeoutMs = (int) ($args['busy-timeout'] ?? 5000);
    $fts5 = ($args['fts5'] ?? '0') === '1';
    $fullfsync = ($args['fullfsync'] ?? '0') === '1';
    $skipBackupProbe = ($args['skip-backup-probe'] ?? '0') === '1';
    $dbDir = $args['db-dir'] ?? __DIR__.'/tmp';
    $outFile = $args['out'] ?? __DIR__.'/results/'.date('Y-m-d').'-'.php_uname('n').'.json';

    if (! is_dir($dbDir) && ! mkdir($dbDir, 0755, true)) {
        throw new RuntimeException("cannot create {$dbDir}");
    }

    $probe = new PDO('sqlite::memory:');
    $compileOptions = $probe->query('PRAGMA compile_options')->fetchAll(PDO::FETCH_COLUMN);
    $environment = [
        'date' => date('c'),
        'host' => php_uname('n'),
        'os' => php_uname('s').' '.php_uname('r').' '.php_uname('m'),
        'php' => PHP_VERSION,
        'php_binary' => PHP_BINARY,
        'sqlite' => $probe->query('select sqlite_version()')->fetchColumn(),
        'fts5' => in_array('ENABLE_FTS5', $compileOptions, true),
        'threadsafe' => in_array('THREADSAFE=1', $compileOptions, true),
        'default_wal_autocheckpoint' => 1000,
        'cpu' => trim((string) shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null')),
        'cores' => (int) trim((string) shell_exec('sysctl -n hw.ncpu 2>/dev/null')),
        'memory_bytes' => (int) trim((string) shell_exec('sysctl -n hw.memsize 2>/dev/null')),
        'db_dir' => realpath($dbDir),
    ];
    $probe = null;

    out("ADR-002 WAL contention benchmark — PHP {$environment['php']} · SQLite {$environment['sqlite']} · FTS5 ".($environment['fts5'] ? 'yes' : 'no'));
    out("  {$environment['cpu']} · {$environment['cores']} cores · ".round($environment['memory_bytes'] / 2 ** 30)." GB · {$environment['os']}");

    $scenarios = [];

    foreach ($syncModes as $sync) {
        foreach ($writerCounts as $writers) {
            $scenarios[] = runScenario($dbDir, $writers, $totalRows, $batch, $sync, $busyTimeoutMs, $fts5, $fullfsync);
        }
    }

    $backup = null;

    if (! $skipBackupProbe) {
        out('  backup probe (VACUUM INTO vs sqlite3_backup) ...');
        $backup = runBackupProbe($dbDir, $totalRows);
        out(sprintf(
            '    %d rows, %.1f MB · VACUUM INTO %.0f ms (%s) · backup API %.0f ms (%s)',
            $backup['rows'],
            $backup['source_bytes'] / 2 ** 20,
            $backup['vacuum_into']['ms'],
            $backup['vacuum_into']['integrity'],
            $backup['backup_api']['ms'],
            $backup['backup_api']['integrity']
        ));
    }

    if (! is_dir(dirname($outFile))) {
        mkdir(dirname($outFile), 0755, true);
    }

    file_put_contents($outFile, json_encode([
        'environment' => $environment,
        'parameters' => ['total_rows' => $totalRows, 'batch' => $batch, 'busy_timeout_ms' => $busyTimeoutMs, 'writers' => $writerCounts, 'synchronous' => $syncModes, 'fts5' => $fts5, 'fullfsync' => $fullfsync],
        'scenarios' => $scenarios,
        'backup_probe' => $backup,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

    out("  results written to {$outFile}");
}

$args = parseArgs($argv);

match ($args['child'] ?? null) {
    'writer' => runWriter($args),
    'reader' => runReader($args),
    default => runParent($args),
};
