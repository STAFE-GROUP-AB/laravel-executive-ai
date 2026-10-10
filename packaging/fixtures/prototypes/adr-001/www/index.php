<?php

declare(strict_types=1);

// ADR-001 prototype probe. Deliberately trivial: the measurement is server cold start,
// not PHP execution. Mirrors the shape of /health/live (blueprint §16 step 4).

header('Content-Type: application/json');

echo json_encode([
    'status' => 'live',
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'zts' => ZEND_THREAD_SAFE,
    'opcache_jit' => ini_get('opcache.jit'),
    'time' => microtime(true),
], JSON_THROW_ON_ERROR);
