<?php

declare(strict_types=1);

// ADR-001 prototype: report what the bundled runtime actually provides, measured against the
// Laravel 13 server requirements (https://laravel.com/docs/13.x/deployment#server-requirements).

$required = [
    'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre',
    'pdo', 'pdo_sqlite', 'sqlite3', 'session', 'tokenizer', 'xml',
];

$useful = ['Zend OPcache', 'intl', 'bcmath', 'zip', 'gd', 'sodium', 'redis', 'pcntl', 'posix', 'imap', 'exif', 'zlib', 'iconv'];

$report = [
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'zts' => ZEND_THREAD_SAFE,
    'arch' => php_uname('m'),
    'opcache_enabled' => (bool) ini_get('opcache.enable'),
    'opcache_jit' => ini_get('opcache.jit'),
    'opcache_jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
    'missing_required' => array_values(array_filter($required, fn (string $e) => ! extension_loaded($e))),
    'present_useful' => array_values(array_filter($useful, fn (string $e) => extension_loaded($e))),
    'missing_useful' => array_values(array_filter($useful, fn (string $e) => ! extension_loaded($e))),
    'sqlite_version' => class_exists(SQLite3::class) ? SQLite3::version()['versionString'] : null,
    'sqlite_fts5' => class_exists(SQLite3::class)
        ? (bool) (new SQLite3(':memory:'))->querySingle("SELECT count(*) FROM pragma_compile_options WHERE compile_options = 'ENABLE_FTS5'")
        : null,
    'extension_count' => count(get_loaded_extensions()),
];

header('Content-Type: application/json');
echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
