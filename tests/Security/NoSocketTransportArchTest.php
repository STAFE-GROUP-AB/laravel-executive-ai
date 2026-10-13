<?php

declare(strict_types=1);

use App\Security\Egress\EgressGuard;
use App\Security\Egress\EgressPurpose;
use App\Security\Egress\EgressServiceProvider;
use App\Security\Egress\HostResolver;
use Illuminate\Http\Client\Factory as HttpFactory;

/*
 * The arch half of S2-14, and the part worth more than the guard itself: the
 * guard only helps where it is called, so this test fails when a **new**
 * outbound call site appears that does not go through it.
 *
 * Three rules, each closing a different hole.
 *
 * 1. **Every outbound call site is listed.** A file in `app/` that can open an
 *    outbound connection — the `Http` facade, the HTTP client factory, a Guzzle
 *    client, curl, a raw socket, `fopen()` on a URL — must appear in
 *    `EGRESS_CALL_SITES` with a sentence saying how it is guarded, and must
 *    either be the guard itself or label its purpose. Adding one without
 *    reading this file fails CI, which is the point: the next outbound path in
 *    this product is `Browser/Fetch` (S2-16), and it must not be able to ship
 *    unguarded by accident.
 * 2. **No non-TCP transport, anywhere.** Threat **T-27** is a request aimed at
 *    `/var/run/docker.sock`, the secrets broker socket or the automation socket
 *    through a Unix-socket client option. The sockets defend themselves
 *    (ADR-003 D3.2, ADR-005 D3.3), but no ADR forbade the client option — the
 *    **GAP** this closes. No HTTP client in the codebase may be constructed
 *    with one.
 * 3. **The veto is installed.** ADR-004 D4 layer 3 only exists while something
 *    registers it, and its residual risk is that it depends on the SDK using
 *    Laravel's `Http`. A test that the middleware is on the factory is what
 *    turns "we registered it once" into a standing claim.
 */

/**
 * Every outbound call site in `app/`, and how each one is guarded. A file here
 * must either live inside `app/Security/Egress/` or name an `EgressPurpose`.
 *
 * @var array<string, string>
 */
const EGRESS_CALL_SITES = [
    'app/Security/Egress/EgressServiceProvider.php' => 'installs the ADR-004 D4 layer-3 veto on the HTTP client factory',
    'app/AI/Providers/Detection/LoopbackProbe.php' => 'labels every request EgressPurpose::LocalRuntime and dials only the 127.0.0.1 literal',
];

/**
 * What makes a file an outbound call site. Types are deliberately absent:
 * importing `Illuminate\Http\Client\Response` or `RequestException` to map an
 * error is not making a request, and a rule that caught those would be noise
 * that teaches people to edit the allowlist without thinking.
 *
 * @var array<string, string>
 */
const OUTBOUND_SIGNATURES = [
    'the Http facade' => '/\bHttp::(?!fake|preventStrayRequests|assert)/',
    'the HTTP client factory' => '/Illuminate\\\\Http\\\\Client\\\\(Factory|PendingRequest)\b/',
    'a Guzzle client' => '/\bnew\s+(\\\\)?GuzzleHttp\\\\Client\b|GuzzleHttp\\\\Client::/',
    'curl' => '/\bcurl_(init|exec|setopt|multi_init)\s*\(/',
    'a raw socket' => '/\b(fsockopen|pfsockopen|stream_socket_client|socket_create|socket_connect)\s*\(/',
    'a URL stream' => '/\b(file_get_contents|fopen|readfile)\s*\(\s*[\'"]\w+:\/\//',
];

/**
 * The client options that would turn an HTTP client into a bridge to a local
 * Unix socket or a named pipe (threat T-27). Forbidden everywhere in `app/`
 * with no exemption: nothing in this product has a reason to point an HTTP
 * client at a socket path.
 *
 * @var array<string, string>
 */
const FORBIDDEN_CLIENT_TRANSPORTS = [
    'CURLOPT_UNIX_SOCKET_PATH' => '/CURLOPT_UNIX_SOCKET_PATH/',
    'CURLOPT_ABSTRACT_UNIX_SOCKET' => '/CURLOPT_ABSTRACT_UNIX_SOCKET/',
    'a curl_unix_socket option' => '/curl_unix_socket/i',
    'a unix_socket client option' => '/[\'"]unix_socket[\'"]/',
    'an http+unix base URI' => '/http\+unix/',
];

/**
 * URL forms that are not TCP at all. Forbidden outside the local-IPC allowlist,
 * because our own broker socket is addressed exactly this way and must be.
 *
 * @var array<string, string>
 */
const FORBIDDEN_URL_TRANSPORTS = [
    'a unix: URL' => '/[\'"]unix:\/\//',
    'a named pipe path' => '/\\\\\\\\\\\\\\\\\.\\\\\\\\pipe/',
];

/**
 * Our own loopback IPC sockets. These are not egress and not HTTP clients: the
 * secrets broker is reached over a Unix socket on purpose (ADR-003 D3.2) and
 * refuses any peer that is not a verified descendant of the Supervisor, so the
 * socket defends itself. What T-27 forbids is an **HTTP client** gaining a
 * socket transport, which is a different thing and has no exemption at all —
 * and the test below holds this list to exactly that distinction, so it cannot
 * quietly become the place where an unguarded HTTP call goes to hide.
 *
 * @var array<string, string>
 */
const LOCAL_IPC_SOCKETS = [
    'app/Security/Secrets/SupervisorSecretStore.php' => 'the ADR-003 D3.2 secrets broker socket: our own Unix socket, peer-verified by the broker, never an HTTP client',
];

/**
 * The guard's own package states the forbidden schemes in order to refuse them,
 * so scanning it for them would fail on the control itself.
 */
const TRANSPORT_SCAN_EXEMPT = 'app/Security/Egress/';

/** @return list<string> every PHP file under app/, repository-relative */
function appPhpFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/** The file's PHP code with comments and docblocks removed, so prose about a rule is not the rule. */
function codeOf(string $relativePath): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents(base_path($relativePath))) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('lists every outbound call site in app/, so a new one cannot bypass the guard unnoticed', function (): void {
    $found = [];

    foreach (appPhpFiles() as $file) {
        $code = codeOf($file);

        foreach (OUTBOUND_SIGNATURES as $what => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[$file][] = $what;
            }
        }
    }

    $unlisted = array_values(array_diff(array_keys($found), array_keys(EGRESS_CALL_SITES), array_keys(LOCAL_IPC_SOCKETS)));
    $stale = array_values(array_diff(array_keys(EGRESS_CALL_SITES), array_keys($found)));

    expect($unlisted)->toBe([], implode("\n", array_map(
        static fn (string $file): string => "{$file} makes an outbound request (".implode(', ', $found[$file]).') but is not in EGRESS_CALL_SITES: route it through App\\Security\\Egress\\EgressGuard, declare its EgressPurpose, and add it to the table with a sentence saying how it is guarded',
        $unlisted,
    )));

    expect($stale)->toBe([], 'EGRESS_CALL_SITES names a file that no longer makes an outbound request; remove the row');
});

it('makes every listed call site either the guard or a caller that declares its purpose', function (): void {
    foreach (EGRESS_CALL_SITES as $file => $how) {
        if (str_starts_with($file, TRANSPORT_SCAN_EXEMPT)) {
            continue;
        }

        expect(str_contains(codeOf($file), class_basename(EgressPurpose::class)))->toBeTrue(
            "{$file} is listed as \"{$how}\" but never names an EgressPurpose, so the guard cannot tell which rules apply to it",
        );
    }
});

it('constructs no HTTP client with a Unix-socket or named-pipe transport', function (): void {
    $offenders = [];

    foreach (appPhpFiles() as $file) {
        if (str_starts_with($file, TRANSPORT_SCAN_EXEMPT)) {
            continue;
        }

        $code = codeOf($file);

        foreach (FORBIDDEN_CLIENT_TRANSPORTS as $what => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $offenders[] = "{$file} uses {$what}";
            }
        }

        if (array_key_exists($file, LOCAL_IPC_SOCKETS)) {
            continue;
        }

        foreach (FORBIDDEN_URL_TRANSPORTS as $what => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $offenders[] = "{$file} uses {$what}";
            }
        }
    }

    expect($offenders)->toBe([], 'T-27: an HTTP client with a socket transport is a bridge to our own privileged sockets');
});

it('keeps the local-IPC exemption to the files that really are local IPC', function (): void {
    // The exemption is the one place this test could be widened by habit, so it
    // is asserted rather than trusted: each exempt file must actually speak to a
    // socket path and must not be an HTTP client at all.
    foreach (LOCAL_IPC_SOCKETS as $file => $why) {
        $code = codeOf($file);

        expect(preg_match('/[\'"]unix:\/\//', $code))->toBe(1, "{$file} is exempt as \"{$why}\" but addresses no socket")
            ->and(preg_match(OUTBOUND_SIGNATURES['the Http facade'], $code))->toBe(0, "{$file} is exempt as local IPC but uses the Http facade")
            ->and(preg_match(OUTBOUND_SIGNATURES['the HTTP client factory'], $code))->toBe(0, "{$file} is exempt as local IPC but uses the HTTP client");
    }
});

it('names every non-TCP scheme the guard refuses, so the refusal is a list and not a habit', function (): void {
    // The other side of the same rule: the guard must *know* the schemes, or the
    // arch rule above would be the only thing standing between a URL and a
    // socket — and an arch rule only runs in CI.
    expect(EgressGuard::NON_TCP_SCHEMES)
        ->toContain('unix')
        ->toContain('http+unix')
        ->toContain('npipe')
        ->toContain('file')
        ->toContain('php')
        ->toContain('data')
        ->toContain('phar');
});

it('installs the layer-3 veto on the HTTP client factory', function (): void {
    // D4's own residual risk is that layer 3 depends on the SDK using Laravel's
    // `Http`. This is the standing assertion that our half of that is in place.
    expect(app(HttpFactory::class)->getGlobalMiddleware())
        ->toHaveCount(1, 'the egress veto must be the global HTTP middleware; a second one needs a decision, not a surprise');
});

it('binds the guard, the resolver and the purpose option as the product uses them', function (): void {
    expect(app(EgressGuard::class))->toBeInstanceOf(EgressGuard::class)
        ->and(app(HostResolver::class))->toBeInstanceOf(HostResolver::class)
        // The option key is part of the contract between a caller and the veto;
        // renaming it silently would un-label every labelled call site.
        ->and(EgressServiceProvider::PURPOSE_OPTION)->toBe('lea_egress_purpose');
});
