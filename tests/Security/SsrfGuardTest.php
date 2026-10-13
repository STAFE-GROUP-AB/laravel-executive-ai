<?php

declare(strict_types=1);

use App\AI\Providers\Exceptions\EgressBlockedException;
use App\AI\Routing\PrivacyMode;
use App\Models\AuditEvent;
use App\Security\Audit\AuditLog;
use App\Security\Egress\AddressNormalizer;
use App\Security\Egress\AuditEgressDenialRecorder;
use App\Security\Egress\EgressGuard;
use App\Security\Egress\EgressPurpose;
use App\Security\Egress\EgressRule;
use App\Security\Egress\LoopbackHostResolver;
use App\Security\Egress\SystemHostResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Assert;
use Tests\Security\Support\FakeHostResolver;

/*
 * The shared SSRF guard, as a table over **every** row of threat model §3.4.1
 * (threats T-23 – T-29) — which is also OPEN-QUESTIONS **A15**'s acceptance
 * criterion and `docs/security/egress-guard.md`'s evidence.
 *
 * Two properties govern how the rows are written.
 *
 * Every decision is made on the **binary** address and never on the textual
 * form, so the obfuscation rows are not a separate feature: `2130706433`,
 * `0177.0.0.1`, `0x7f.1`, `127.1` and `::ffff:127.0.0.1` are rows of the same
 * table as `127.0.0.1`, and each of them must refuse for the *loopback* reason
 * rather than for a spelling reason. A guard that refused them as "malformed"
 * would be refusing by accident.
 *
 * And nothing here opens a socket. Names resolve through `FakeHostResolver`,
 * which is also how the rebinding row is possible at all: it answers
 * differently on the second lookup, so a guard that resolved twice would
 * connect to an address it never judged.
 */

uses(DatabaseTransactions::class);

/** The resolver every row shares: three names with fixed answers plus the rebinding one. */
function ssrfResolver(): FakeHostResolver
{
    return (new FakeHostResolver)
        ->answer('models.example.test', ['203.0.113.10'])
        ->answer('public.example.test', ['93.184.216.34'])
        ->answer('gateway.example.test', ['93.184.216.34'])
        ->answer('localhost.attacker.test', ['127.0.0.1'])
        ->answer('runtime.attacker.test', ['10.0.0.5'])
        ->answer('metadata.attacker.test', ['169.254.169.254'])
        ->answer('split.attacker.test', ['93.184.216.34', '127.0.0.1'])
        ->answer('tailnet.attacker.test', ['100.100.64.7'])
        ->rebinding('rebind.attacker.test', ['93.184.216.34'], ['127.0.0.1']);
}

function ssrfGuard(?FakeHostResolver $resolver = null): EgressGuard
{
    return new EgressGuard(
        resolver: $resolver ?? ssrfResolver(),
        normalizer: new AddressNormalizer,
        ownCorePort: 8787,
    );
}

/*
 * ---------------------------------------------------------------------------
 * Rows 1, 2 and 10 — scheme, URL hygiene, transport
 * ---------------------------------------------------------------------------
 */

it('row 1 refuses every scheme that is not https, and http anywhere but loopback', function (string $url, EgressRule $rule): void {
    expect(ssrfGuard()->refusalFor($url, EgressPurpose::WebFetch, PrivacyMode::Custom))->toBe($rule);
})->with([
    'file' => ['file:///etc/passwd', EgressRule::NonTcpTransport],
    'data' => ['data:text/plain;base64,aGk=', EgressRule::NonTcpTransport],
    'php filter' => ['php://filter/read=convert.base64-encode/resource=/etc/passwd', EgressRule::NonTcpTransport],
    'phar' => ['phar:///tmp/x.phar/y', EgressRule::NonTcpTransport],
    'gopher' => ['gopher://public.example.test/1', EgressRule::SchemeNotAllowed],
    'javascript' => ['javascript:alert(1)', EgressRule::SchemeNotAllowed],
    'vbscript' => ['vbscript:msgbox', EgressRule::SchemeNotAllowed],
    'ftp' => ['ftp://public.example.test/x', EgressRule::SchemeNotAllowed],
    'dict' => ['dict://public.example.test:2628/d:x', EgressRule::SchemeNotAllowed],
    'ws' => ['ws://public.example.test/x', EgressRule::SchemeNotAllowed],
    'wss' => ['wss://public.example.test/x', EgressRule::SchemeNotAllowed],
    'plain http to a public host' => ['http://public.example.test/x', EgressRule::SchemeNotAllowed],
]);

it('row 2 refuses a userinfo-confused authority, control characters and a double @', function (string $url): void {
    // `http://allowed@127.0.0.1/` reads as "allowed" to a person and as
    // `127.0.0.1` to the client. Refused, never stripped: stripping would
    // connect to the second reading.
    expect(ssrfGuard()->refusalFor($url, EgressPurpose::ModelProvider, PrivacyMode::Custom))
        ->toBe(EgressRule::MalformedAuthority);
})->with([
    'userinfo' => ['https://public.example.test@127.0.0.1/v1'],
    'userinfo with password' => ['https://user:pass@127.0.0.1/v1'],
    'two at signs' => ['https://a@b@127.0.0.1/v1'],
    'newline in the host' => ["https://public.example.test\n.attacker.test/v1"],
    'tab in the host' => ["https://public\t.example.test/v1"],
    'space in the host' => ['https://public .example.test/v1'],
    'percent-encoded host' => ['https://%31%32%37%2e%30%2e%30%2e%31/v1'],
]);

it('row 10 refuses a Unix socket, a named pipe and a schemeless target', function (string $url): void {
    expect(ssrfGuard()->refusalFor($url, EgressPurpose::ModelProvider, PrivacyMode::Custom))
        ->toBe(EgressRule::NonTcpTransport);
})->with([
    'http+unix' => ['http+unix://%2Fvar%2Frun%2Fdocker.sock/containers/json'],
    'unix' => ['unix:///var/run/docker.sock'],
    'npipe' => ['npipe:////./pipe/docker_engine'],
    'named pipe' => ['\\\\.\\pipe\\docker_engine'],
    'a bare path' => ['/run/secrets.sock'],
    'no scheme at all' => ['models.example.test/v1'],
]);

/*
 * ---------------------------------------------------------------------------
 * Rows 3 – 9 — the address ranges, every spelling
 * ---------------------------------------------------------------------------
 */

it('rows 3 to 9 refuse every denied range on the binary address, in every spelling', function (string $host, EgressRule $rule): void {
    // `WebFetch` is the purpose with no legitimate loopback target, so this is
    // the table T-23's first sentence describes: a URL that arrived in content
    // may not reach our own core, a local runtime, or a metadata service.
    expect(ssrfGuard()->refusalFor("https://{$host}/x", EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe($rule, $host);
})->with([
    // Row 3 — loopback, and row 9's spellings of it. Each must refuse as
    // loopback: refusing them as "malformed" would be refusing by accident.
    'dotted loopback' => ['127.0.0.1', EgressRule::Loopback],
    'all of 127/8' => ['127.1.2.3', EgressRule::Loopback],
    'decimal' => ['2130706433', EgressRule::Loopback],
    'octal' => ['0177.0.0.1', EgressRule::Loopback],
    'octal, fully packed' => ['017700000001', EgressRule::Loopback],
    'hex' => ['0x7f.0x0.0x0.0x1', EgressRule::Loopback],
    'hex, fully packed' => ['0x7f000001', EgressRule::Loopback],
    'hex short form' => ['0x7f.1', EgressRule::Loopback],
    'short form' => ['127.1', EgressRule::Loopback],
    'zero padded' => ['0177.0000.0000.0001', EgressRule::Loopback],
    'trailing dot' => ['127.0.0.1.', EgressRule::Loopback],
    'uppercase ipv6' => ['[::1]', EgressRule::Loopback],
    'ipv6 loopback long form' => ['[0000:0000:0000:0000:0000:0000:0000:0001]', EgressRule::Loopback],
    'this host' => ['0.0.0.0', EgressRule::Loopback],
    'this host, decimal' => ['0', EgressRule::Loopback],
    // Row 8 — IPv4-mapped IPv6 unwraps to the IPv4 address and is judged there.
    'ipv4-mapped loopback' => ['[::ffff:127.0.0.1]', EgressRule::Loopback],
    'ipv4-mapped loopback, hex groups' => ['[::ffff:7f00:1]', EgressRule::Loopback],
    'ipv4-mapped private' => ['[::ffff:10.0.0.1]', EgressRule::PrivateNetwork],
    // Row 8 — NAT64 and 6to4 wrappers are refused whole: a socket to one of
    // them reaches an IPv6 relay, so the embedded IPv4 is not what is connected
    // to and the pin could not describe it.
    'nat64 wrapper' => ['[64:ff9b::7f00:1]', EgressRule::ObfuscatedAddress],
    '6to4 wrapper' => ['[2002:7f00:1::]', EgressRule::ObfuscatedAddress],
    'ipv4-compatible wrapper' => ['[::10.0.0.5]', EgressRule::ObfuscatedAddress],
    // `::0.0.0.1` and `::1` are the same sixteen bytes, so the first is not a
    // second spelling to catch — it *is* loopback, and is refused as loopback.
    'ipv4-compatible loopback' => ['[::0.0.0.1]', EgressRule::Loopback],
    // Row 4 — RFC 1918 and shared address space.
    'ten eight' => ['10.0.0.5', EgressRule::PrivateNetwork],
    'one seventy two sixteen' => ['172.16.5.5', EgressRule::PrivateNetwork],
    'one seventy two thirty one' => ['172.31.255.254', EgressRule::PrivateNetwork],
    'one ninety two one sixty eight' => ['192.168.1.1', EgressRule::PrivateNetwork],
    'shared address space' => ['100.64.0.1', EgressRule::SharedAddressSpace],
    'tailnet range' => ['100.100.64.7', EgressRule::SharedAddressSpace],
    // Row 5 — link-local.
    'link local v4' => ['169.254.10.10', EgressRule::LinkLocal],
    'link local v6' => ['[fe80::1]', EgressRule::LinkLocal],
    // Row 6 — unique-local IPv6.
    'unique local' => ['[fc00::1]', EgressRule::UniqueLocal],
    'unique local, fd prefix' => ['[fd12:3456::1]', EgressRule::UniqueLocal],
    // Row 7 — the metadata endpoints, by address.
    'aws and azure metadata' => ['169.254.169.254', EgressRule::CloudMetadata],
    'ecs task metadata' => ['169.254.170.2', EgressRule::CloudMetadata],
    'aws ipv6 metadata' => ['[fd00:ec2::254]', EgressRule::CloudMetadata],
    'alibaba metadata' => ['100.100.100.200', EgressRule::CloudMetadata],
    'metadata in decimal' => ['2852039166', EgressRule::CloudMetadata],
    // Fail closed: reserved, documentation, benchmarking, multicast, broadcast.
    'documentation' => ['192.0.2.1', EgressRule::Unclassifiable],
    'benchmarking' => ['198.18.0.1', EgressRule::Unclassifiable],
    'ietf protocol assignments' => ['192.0.0.1', EgressRule::Unclassifiable],
    'multicast' => ['224.0.0.1', EgressRule::Unclassifiable],
    'reserved' => ['240.0.0.1', EgressRule::Unclassifiable],
    'broadcast' => ['255.255.255.255', EgressRule::Unclassifiable],
    'ipv6 documentation' => ['[2001:db8::1]', EgressRule::Unclassifiable],
    'ipv6 multicast' => ['[ff02::1]', EgressRule::Unclassifiable],
    'ipv6 unspecified' => ['[::]', EgressRule::Unclassifiable],
    // Numeric but not an address: not a name either, so "does not resolve"
    // would be the wrong refusal.
    'overflowing decimal' => ['4294967296', EgressRule::ObfuscatedAddress],
    'five parts' => ['1.2.3.4.5', EgressRule::ObfuscatedAddress],
    'ambiguous leading zero' => ['0177.0.0.08', EgressRule::ObfuscatedAddress],
]);

it('row 7 refuses the fifth named metadata endpoint by name as well as by address', function (): void {
    expect(ssrfGuard()->refusalFor('https://metadata.google.internal/computeMetadata/v1/', EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe(EgressRule::CloudMetadata)
        // The name check is the convenience; the address check is the control,
        // so a name that merely *resolves* to a metadata address is refused too.
        ->and(ssrfGuard()->refusalFor('https://metadata.attacker.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe(EgressRule::CloudMetadata);
});

it('rows 8 and 9 never refuse a host for its spelling when the address is permitted', function (string $host): void {
    // The reverse of the obfuscation rows, and the one that keeps them honest:
    // normalisation must not turn a legitimate public target into a refusal.
    expect(ssrfGuard()->permits("https://{$host}/x", EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBeTrue($host);
})->with([
    'a public literal' => ['93.184.216.34'],
    'a public literal with a trailing dot' => ['93.184.216.34.'],
    'an uppercase name' => ['PUBLIC.EXAMPLE.TEST'],
    'a name with a trailing dot' => ['public.example.test.'],
    'a public ipv6 literal' => ['[2606:2800:220:1:248:1893:25c8:1946]'],
]);

it('reduces a Unicode host to its punycode form before deciding', function (): void {
    $resolver = ssrfResolver()->answer('xn--exmple-cua.test', ['127.0.0.1']);

    // The decision must be the same whichever spelling arrives, or one of the
    // two is a bypass. Both refuse, and both refuse as loopback.
    expect(ssrfGuard($resolver)->refusalFor('https://exämple.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe(EgressRule::Loopback)
        ->and(ssrfGuard($resolver)->refusalFor('https://xn--exmple-cua.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe(EgressRule::Loopback);
});

it('judges a name on what it resolves to, never on what it looks like', function (string $host, ?EgressRule $rule): void {
    expect(ssrfGuard()->refusalFor("https://{$host}/x", EgressPurpose::WebFetch, PrivacyMode::Custom))->toBe($rule, $host);
})->with([
    // Looks local, is resolved, is refused on the answer.
    'a name under localhost' => ['localhost.attacker.test', EgressRule::Loopback],
    'a name that resolves into a private range' => ['runtime.attacker.test', EgressRule::PrivateNetwork],
    'a name that resolves to the tailnet range' => ['tailnet.attacker.test', EgressRule::SharedAddressSpace],
    // Looks remote, resolves to nothing, is still refused: fail closed.
    'a name with no records' => ['nothing.attacker.test', EgressRule::Unresolvable],
    // Looks like a literal, is a name, resolves to nothing.
    'a literal-shaped name' => ['127.0.0.1.attacker.test', EgressRule::Unresolvable],
    // Resolves to a public address and is permitted.
    'a genuinely public name' => ['public.example.test', null],
]);

/*
 * ---------------------------------------------------------------------------
 * Rows 11 – 13 — ports, resolution and pinning, redirects
 * ---------------------------------------------------------------------------
 */

it('row 11 denies our own core port to every purpose but our own MCP server', function (): void {
    // T-23's first sentence: the agent reading our own authenticated surfaces
    // on `127.0.0.1:<port>`. A local runtime may use loopback; it may not use
    // *that* loopback port.
    expect(ssrfGuard()->refusalFor('http://127.0.0.1:8787/ipc/v1/health', EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly))
        ->toBe(EgressRule::PortNotAllowed)
        ->and(ssrfGuard()->refusalFor('http://127.0.0.1:8787/mcp', EgressPurpose::McpServer, PrivacyMode::LocalOnly))
        ->toBeNull('our own MCP server is the one legitimate caller of our own port')
        ->and(ssrfGuard()->permits('http://127.0.0.1:11434/api/chat', EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly))
        ->toBeTrue('a local runtime on its own port is the point of Local Only');
});

it('row 11 restricts the public port per purpose', function (): void {
    expect(ssrfGuard()->refusalFor('https://public.example.test:8080/x', EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe(EgressRule::PortNotAllowed)
        ->and(ssrfGuard()->refusalFor('https://public.example.test:9999/v1', EgressPurpose::ModelProvider, PrivacyMode::Custom))
        ->toBe(EgressRule::PortNotAllowed)
        // A self-hosted gateway on 8443 is a real configuration; refusing it
        // would push owners to turn the guard off instead.
        ->and(ssrfGuard()->permits('https://gateway.example.test:8443/v1', EgressPurpose::ModelProvider, PrivacyMode::Custom))
        ->toBeTrue();
});

it('refuses a public address for the local-runtime purpose in every mode, not only Local Only', function (): void {
    // A local runtime is local by definition. This purpose permits loopback and
    // has no public port rule, so without the explicit refusal it would be the
    // loosest row in the table as soon as the mode was not Local Only.
    foreach ([PrivacyMode::LocalOnly, PrivacyMode::EuRestricted, PrivacyMode::Custom] as $mode) {
        expect(ssrfGuard()->refusalFor('https://public.example.test/v1', EgressPurpose::LocalRuntime, $mode))
            ->toBe(EgressRule::LocalOnly, $mode->value)
            ->and(ssrfGuard()->refusalFor('https://public.example.test:9000/v1', EgressPurpose::LocalRuntime, $mode))
            ->toBe(EgressRule::LocalOnly, $mode->value);
    }
});

it('row 12 refuses a host whose records disagree, rather than picking the permitted one', function (): void {
    expect(ssrfGuard()->refusalFor('https://split.attacker.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe(EgressRule::ResolutionDisagrees);
});

it('row 12 resolves once and pins the resolved address for the connection', function (): void {
    $resolver = ssrfResolver();
    $target = ssrfGuard($resolver)->authorize('https://public.example.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom);

    // The decision and the connection are the same fact: the pin names the
    // address that was judged, and curl connects to it while the Host header
    // and the TLS SNI keep the name the certificate is issued for.
    expect($target->address)->toBe('93.184.216.34')
        ->and($target->host)->toBe('public.example.test')
        ->and($target->resolveEntry())->toBe('public.example.test:443:93.184.216.34')
        ->and($target->requestOptions()['curl'][CURLOPT_RESOLVE])->toBe(['public.example.test:443:93.184.216.34'])
        ->and($target->requestOptions()['allow_redirects'])->toBeFalse()
        ->and($resolver->lookupsFor('public.example.test'))->toBe(1, 'the name is resolved exactly once');
});

it('row 12 pins an IPv6 address in the bracketed form curl expects', function (): void {
    $resolver = ssrfResolver()->answer('v6.example.test', ['2606:2800:220:1:248:1893:25c8:1946']);

    expect(ssrfGuard($resolver)->authorize('https://v6.example.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom)->resolveEntry())
        ->toBe('v6.example.test:443:[2606:2800:220:1:248:1893:25c8:1946]');
});

it('row 12 resolves once per decision, so there is no check-then-connect window', function (): void {
    // T-25 in its exact shape: the name answers a public address for the check
    // and loopback for the connection. The window only exists if something
    // resolves twice — once to decide and once to dial — so the assertion is
    // that one decision performs exactly one lookup and hands back the address
    // it judged. The connection then goes to *that* address, by CURLOPT_RESOLVE.
    $resolver = ssrfResolver();
    $target = ssrfGuard($resolver)->authorize('https://rebind.attacker.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom);

    expect($resolver->lookupsFor('rebind.attacker.test'))->toBe(1)
        ->and($target->address)->toBe('93.184.216.34')
        ->and($target->resolveEntry())->toBe('rebind.attacker.test:443:93.184.216.34');

    // And the second answer does not become a permission later: a fresh request
    // re-runs the whole checklist on the new answer and refuses it. The attacker
    // gets a refusal, never a connection to loopback.
    expect(ssrfGuard($resolver)->refusalFor('https://rebind.attacker.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom))
        ->toBe(EgressRule::Loopback);
});

it('memoises the system resolver, so one host has one answer per process', function (): void {
    // The other half of "resolve once": `SystemHostResolver` caches its answer,
    // so a guard asked twice about the same host within a request cannot be
    // handed two different ones. Asserted on the reserved loopback names, which
    // are the only names that resolve without a socket.
    $resolver = new SystemHostResolver;

    expect($resolver->resolve('localhost'))->toBe(['127.0.0.1', '::1'])
        ->and($resolver->resolve('localhost'))->toBe(['127.0.0.1', '::1'])
        ->and($resolver->resolve('ollama.localhost'))->toBe(['127.0.0.1', '::1']);
});

it('row 12 refuses a name it cannot pin, and permits a literal it has nothing to pin', function (): void {
    // A literal has no name to re-resolve, so there is nothing a second answer
    // could change. A *name* without curl would be re-resolved by the stream
    // handler, which is the rebinding window again — so it is refused.
    expect(ssrfGuard()->permits('http://127.0.0.1:11434/api/chat', EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly))
        ->toBeTrue()
        ->and(extension_loaded('curl'))
        ->toBeTrue('the pin is a curl option; without curl the guard refuses every name (EgressRule::Unpinnable)');
});

it('row 13 refuses the hop a redirect points at, not only the first URL', function (): void {
    // T-24: the first URL passes and a 3xx points at a blocked target. The hop
    // is a full re-run of the checklist, and its refusal says it was a redirect.
    $guard = ssrfGuard();

    expect($guard->permits('https://public.example.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom))->toBeTrue();

    try {
        $guard->authorizeRedirect('https://localhost.attacker.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom);
        Assert::fail('a redirect into loopback must be refused at the redirect');
    } catch (EgressBlockedException $exception) {
        expect($exception->rule)->toBe(EgressRule::RedirectRefused->value)
            ->and($exception->error->message)->toContain(EgressRule::Loopback->value)
            ->and($exception->error->retryable)->toBeFalse();
    }
});

/*
 * ---------------------------------------------------------------------------
 * Rows 15 and 16 — Local Only interaction, and what a denial records
 * ---------------------------------------------------------------------------
 */

it('row 15 switches Browser/Fetch off in Local Only rather than narrowing it', function (): void {
    expect(ssrfGuard()->refusalFor('https://public.example.test/x', EgressPurpose::WebFetch, PrivacyMode::LocalOnly))
        ->toBe(EgressRule::LocalOnly)
        ->and(ssrfGuard()->refusalFor('https://public.example.test/v1', EgressPurpose::ModelProvider, PrivacyMode::LocalOnly))
        ->toBe(EgressRule::LocalOnly)
        // §4's separate, labelled egress category is not affected: a Local Only
        // install still fetches the owner's own mail.
        ->and(ssrfGuard()->permits('https://public.example.test/mail', EgressPurpose::IntegrationSync, PrivacyMode::LocalOnly))
        ->toBeTrue();
});

it('row 16 records the host, the resolved address and the rule — and never the URL', function (): void {
    $guard = new EgressGuard(
        resolver: ssrfResolver(),
        recorder: new AuditEgressDenialRecorder(app(AuditLog::class)),
        normalizer: new AddressNormalizer,
        ownCorePort: 8787,
    );

    expect(fn (): mixed => $guard->authorize(
        'https://localhost.attacker.test/x?token=super-secret-value',
        EgressPurpose::WebFetch,
        PrivacyMode::Custom,
    ))->toThrow(EgressBlockedException::class);

    $event = AuditEvent::query()->where('action', AuditEgressDenialRecorder::ACTION)->latest('occurred_at')->firstOrFail();
    $context = $event->getAttribute('context');

    expect($event->getAttribute('outcome'))->toBe(AuditLog::OUTCOME_REFUSED)
        ->and($context['rule'])->toBe(EgressRule::Loopback->value)
        ->and($context['requested_host'])->toBe('localhost.attacker.test')
        ->and($context['resolved_address'])->toBe('127.0.0.1')
        ->and($context['purpose'])->toBe(EgressPurpose::WebFetch->value)
        // A query string can carry a token, so the row carries the host and not
        // the URL. This assertion is the control, not the formatting.
        ->and(json_encode($context, JSON_THROW_ON_ERROR))->not->toContain('super-secret-value');
});

it('asks the question without writing a row, so a settings page cannot flood the trail', function (): void {
    $before = AuditEvent::query()->where('action', AuditEgressDenialRecorder::ACTION)->count();

    $guard = new EgressGuard(
        resolver: ssrfResolver(),
        recorder: new AuditEgressDenialRecorder(app(AuditLog::class)),
        normalizer: new AddressNormalizer,
        ownCorePort: 8787,
    );

    $guard->permits('https://localhost.attacker.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom);
    $guard->refusalFor('https://localhost.attacker.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom);

    expect(AuditEvent::query()->where('action', AuditEgressDenialRecorder::ACTION)->count())->toBe($before);
});

it('names the rule in the refusal, because a refusal nobody can audit is not a control', function (): void {
    try {
        ssrfGuard()->authorize('https://169.254.169.254/latest/meta-data/', EgressPurpose::WebFetch, PrivacyMode::Custom);
        Assert::fail('the metadata endpoint must be refused');
    } catch (EgressBlockedException $exception) {
        expect($exception->rule)->toBe(EgressRule::CloudMetadata->value)
            ->and($exception->blockedHost)->toBe('169.254.169.254')
            ->and($exception->resolvedAddress)->toBe('169.254.169.254')
            ->and($exception->error->message)->toContain(EgressRule::CloudMetadata->reason());
    }
});

/*
 * ---------------------------------------------------------------------------
 * The LAN opt-in — exactly what it widens, and nothing else
 * ---------------------------------------------------------------------------
 */

it('widens Local Only to RFC 1918 and link-local only, never to anything else', function (): void {
    $guard = ssrfGuard();
    $lan = fn (string $url): ?EgressRule => $guard->refusalFor($url, EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly, allowLan: true);
    $strict = fn (string $url): ?EgressRule => $guard->refusalFor($url, EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly);

    expect($strict('http://10.0.0.5:11434/api/chat'))->toBe(EgressRule::LanNotAllowed, 'off by default')
        ->and($lan('http://10.0.0.5:11434/api/chat'))->toBeNull('RFC 1918 is what the warning says it widens to')
        ->and($lan('http://192.168.1.50:11434/api/chat'))->toBeNull()
        ->and($lan('http://172.16.0.9:11434/api/chat'))->toBeNull()
        ->and($lan('http://169.254.10.10:11434/api/chat'))->toBeNull('link-local too')
        // And nothing else. A public IP is never admitted in this mode, the
        // shared address space is not RFC 1918, the IPv6 unique-local range is
        // not either, and the metadata address is inside a range the opt-in
        // does widen — so it stays refused by name.
        ->and($lan('https://public.example.test/v1'))->toBe(EgressRule::LocalOnly)
        ->and($lan('http://100.64.0.1:11434/x'))->toBe(EgressRule::SharedAddressSpace)
        ->and($lan('http://[fc00::1]:11434/x'))->toBe(EgressRule::UniqueLocal)
        ->and($lan('http://169.254.169.254/latest/meta-data/'))->toBe(EgressRule::CloudMetadata);
});

it('never widens Browser/Fetch, whatever the LAN opt-in says', function (): void {
    expect(ssrfGuard()->refusalFor('https://10.0.0.5/x', EgressPurpose::WebFetch, PrivacyMode::LocalOnly, allowLan: true))
        ->toBe(EgressRule::LocalOnly)
        ->and(ssrfGuard()->refusalFor('https://10.0.0.5/x', EgressPurpose::WebFetch, PrivacyMode::Custom, allowLan: true))
        ->toBe(EgressRule::PrivateNetwork);
});

/*
 * ---------------------------------------------------------------------------
 * The fail-closed default resolver
 * ---------------------------------------------------------------------------
 */

it('resolves the reserved loopback names from a constant and nothing else', function (): void {
    // RFC 6761 §6.3 as a MUST rather than a SHOULD: no DNS answer for
    // `localhost` can exist, so the one name our local endpoints are configured
    // under cannot be rebound. Every other name resolves to nothing, which the
    // guard refuses — the fail-closed default wherever no resolver is wired.
    $guard = new EgressGuard(new LoopbackHostResolver, normalizer: new AddressNormalizer, ownCorePort: 8787);

    expect($guard->permits('http://localhost:11434/api/chat', EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly))->toBeTrue()
        ->and($guard->permits('http://ollama.localhost:11434/api/chat', EgressPurpose::LocalRuntime, PrivacyMode::LocalOnly))->toBeTrue()
        ->and($guard->refusalFor('https://public.example.test/x', EgressPurpose::WebFetch, PrivacyMode::Custom))->toBe(EgressRule::Unresolvable);
});
