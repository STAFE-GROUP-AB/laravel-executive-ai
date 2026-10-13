<?php

declare(strict_types=1);

namespace App\Security\Egress;

/**
 * What an outbound request is *for*. The SSRF rules are not one list: a local
 * model runtime on `127.0.0.1:11434` is the only legitimate loopback target in
 * the product, and the same address reached from a URL that arrived in an email
 * is threat T-23. The purpose is what separates them, and it is supplied by the
 * caller rather than guessed from the URL — guessing is how the distinction
 * gets lost.
 *
 * `ModelProvider` is the default for an unlabelled request, because that is the
 * category ADR-004 D4 restricts hardest and because the pinned SDK builds its
 * own requests: a request nobody labelled is treated as model traffic and is
 * therefore subject to `LOCAL_ONLY`.
 */
enum EgressPurpose: string
{
    /** ADR-004 D4 model traffic: prompts, embeddings, screenshots, tool transcripts. */
    case ModelProvider = 'model_provider';

    /** §12.3.5 local runtime detection and local inference: loopback, in every mode. */
    case LocalRuntime = 'local_runtime';

    /** §11 `Browser/Fetch`: a URL that came from content, so the strictest rules apply. */
    case WebFetch = 'web_fetch';

    /** ADR-005 D4.2: an MCP server, remote over HTTPS or our own on loopback. */
    case McpServer = 'mcp_server';

    /** §4's separate, transparently labelled egress category: mail and code-host sync. */
    case IntegrationSync = 'integration_sync';

    /**
     * The schemes this purpose accepts (checklist row 1). `http` appears only
     * where a loopback target is legitimate, and the loopback restriction is
     * enforced separately — an `http` URL to a public host is refused by the
     * scheme rule in every purpose.
     *
     * @return list<string>
     */
    public function schemes(): array
    {
        return match ($this) {
            self::ModelProvider, self::LocalRuntime, self::McpServer => ['https', 'http'],
            self::WebFetch, self::IntegrationSync => ['https'],
        };
    }

    /**
     * The ports this purpose accepts on a **public** host (checklist row 11).
     *
     * `443` is the only public port for everything but a model provider, which
     * also accepts `8443`: a self-hosted gateway on that port is a real
     * configuration, and refusing it would push owners to disable the guard
     * instead of narrowing it.
     *
     * An empty list means "this purpose has no public port rule", which is true
     * of `LocalRuntime` only because it has no public target at all — a local
     * runtime listens on whatever port it was configured with (`11434`, `1234`,
     * an MLX server's own), and the restriction that matters there is the
     * loopback one plus the denial of our own core port.
     *
     * @return list<int>
     */
    public function publicPorts(): array
    {
        return match ($this) {
            self::ModelProvider => [443, 8443],
            self::McpServer, self::WebFetch, self::IntegrationSync => [443],
            self::LocalRuntime => [],
        };
    }

    /**
     * Whether a loopback target is legitimate for this purpose at all. False
     * here is what makes `Browser/Fetch` unable to read our own authenticated
     * surfaces on `127.0.0.1:<port>`, which is T-23's first sentence.
     */
    public function allowsLoopback(): bool
    {
        return match ($this) {
            self::ModelProvider, self::LocalRuntime, self::McpServer => true,
            self::WebFetch, self::IntegrationSync => false,
        };
    }

    /**
     * Whether a **public** address is legitimate for this purpose at all.
     *
     * False for `LocalRuntime`, and that is the point of having the purpose: a
     * local runtime is local by definition, so a public address is a
     * misconfiguration or a redirect, never a target — in `CUSTOM` as much as in
     * `LOCAL_ONLY`. Without this the purpose would be the loosest one in the
     * table outside Local Only (loopback allowed *and* no public port rule),
     * which is the opposite of what its name promises.
     */
    public function allowsPublic(): bool
    {
        return $this !== self::LocalRuntime;
    }

    /**
     * Whether `LOCAL_ONLY` restricts this purpose (ADR-004 D4.1, checklist row
     * 15). Mail and code-host synchronisation are a separate, labelled egress
     * category that §4 deliberately keeps outside the model-traffic rule: a
     * Local Only install still fetches the owner's own mail.
     */
    public function restrictedByLocalOnly(): bool
    {
        return $this !== self::IntegrationSync;
    }

    /**
     * Whether the purpose has any permitted target at all under `LOCAL_ONLY`.
     * `Browser/Fetch` and registry discovery are off in that mode rather than
     * narrowed (ADR-004 D4.1; ADR-005 D4.9), so there is nothing to allowlist.
     */
    public function permittedUnderLocalOnly(): bool
    {
        return $this !== self::WebFetch;
    }

    /**
     * Whether our own core's fixed loopback port is a legitimate target. Only
     * our own MCP server is reached that way (ADR-005 D4.10); for every other
     * purpose our own port is the one loopback port that is explicitly denied,
     * so a model provider or a fetch cannot be pointed at our own API.
     */
    public function allowsOwnCorePort(): bool
    {
        return $this === self::McpServer;
    }
}
