<?php

declare(strict_types=1);

namespace App\Providers;

use App\AI\Providers\AdapterFactory;
use App\AI\Providers\AiProviderAdapter;
use App\AI\Providers\Detection\LmStudioDetector;
use App\AI\Providers\Detection\LocalRuntimeDetector;
use App\AI\Providers\Detection\LoopbackProbe;
use App\AI\Providers\Detection\OllamaDetector;
use App\AI\Providers\InstalledProviderConnections;
use App\AI\Providers\LaravelAi\LaravelAiAdapterFactory;
use App\AI\Providers\ProviderConnections;
use App\AI\Providers\ProviderRegistry;
use App\AI\Providers\SupportedProviders;
use App\AI\Routing\ModelCapabilityRegistry;
use App\Security\Privacy\PrivacyModeStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI layer and, with it, performs the one act that makes a provider
 * offerable: binding an `AiProviderAdapter` under
 * `SupportedProviders::adapterBinding($id)`.
 *
 * `docs/architecture/ai-state.md` §2 fixes that mechanism — bind the adapter,
 * flip `adapter_available` in the shared fixture, nothing else — and three
 * tests hold the two sides together: `SupportedProvidersTest` refuses a flag
 * without a binding *and* a binding without the flag, and `ChooseAiScreenTests`
 * pins the Swift mirror to the same fixture. So this file and
 * `packaging/fixtures/ai-providers/ai-providers.json` can only ever change
 * together.
 *
 * The bindings are lazy on purpose. `app()->bound()` is what honesty is
 * measured by — "an adapter for this driver exists in this build" — while
 * actually constructing one needs a configured URL or a Keychain key the owner
 * may not have supplied yet. Resolving the binding is therefore deferred to the
 * first run that needs it, where a missing key surfaces as the provider's own
 * authentication error rather than as a boot failure.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoopbackProbe::class);
        $this->app->singleton(ModelCapabilityRegistry::class, fn (Application $app): ModelCapabilityRegistry => new ModelCapabilityRegistry($app['config']));
        $this->app->singleton(ProviderConnections::class, InstalledProviderConnections::class);
        $this->app->singleton(AdapterFactory::class, LaravelAiAdapterFactory::class);
        $this->app->singleton(ProviderRegistry::class);

        $this->registerDetectors();
        $this->registerAdapters();
    }

    /**
     * The §12.3.5 loopback detectors. They are tagged so the installer's
     * SELECT_AI step can iterate every runtime the build knows about without
     * naming them, and so adding a runtime is one binding.
     */
    private function registerDetectors(): void
    {
        $this->app->singleton(OllamaDetector::class);
        $this->app->singleton(LmStudioDetector::class);

        $this->app->tag([OllamaDetector::class, LmStudioDetector::class], LocalRuntimeDetector::class);
    }

    /**
     * One binding per catalogue row whose driver this build can actually serve.
     *
     * Every v1 row is bound: the adapter is generic over the four ADR-004 D1
     * drivers, so `ollama`, `lmstudio`, `openai`, `anthropic` and a custom
     * OpenAI-compatible endpoint are all real. That is exactly the D6 v1 option
     * list — Anthropic · Custom endpoint · LM Studio · Ollama · OpenAI — and the
     * fixture's `adapter_available` is true for precisely these five.
     *
     * Being offerable is not being selected: nothing here preselects anything,
     * the SELECT_AI draft starts empty, and `/settings/ai` renders the list with
     * `data-ai-preselected="none"`.
     */
    private function registerAdapters(): void
    {
        foreach (SupportedProviders::catalogue() as $provider) {
            if (! $provider->adapterAvailable) {
                continue;
            }

            $id = $provider->id;

            $this->app->bind(
                SupportedProviders::adapterBinding($id),
                function (Application $app) use ($id): AiProviderAdapter {
                    // The stored policy, not the configured seed: S2-14 made
                    // `privacy_policies` the source of truth and `lea.privacy_mode`
                    // the install-time default the first row is written from
                    // (OPEN-QUESTIONS A18).
                    $policy = $app->make(PrivacyModeStore::class)->active();

                    return $app->make(ProviderRegistry::class)->adapterFor($id, $policy->mode, $policy->allowLan);
                },
            );
        }
    }
}
