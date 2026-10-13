Thin provider-neutral adapters over the official Laravel AI SDK; AiProviderAdapter contract and contract tests (§3, §4, ADR-004).

As built (S2-13): docs/architecture/ai-providers.md. `LaravelAi/` is the only directory that may import `Laravel\Ai` — an arch test fails the build otherwise.
