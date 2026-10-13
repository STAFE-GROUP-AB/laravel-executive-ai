<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * Turns a configured connection into a working adapter.
 *
 * This interface exists so `ProviderRegistry` — which decides *whether* a
 * provider may be built — does not have to know *how*, and therefore never
 * imports an SDK type. The only implementation is
 * `LaravelAi\LaravelAiAdapterFactory`, which is also the only place
 * `Laravel\Ai\Ai::build()` is called (ADR-004 D2, enforced by
 * `tests/Security/NoSdkTypeLeakArchTest.php`).
 */
interface AdapterFactory
{
    public function make(ProviderConnection $connection): AiProviderAdapter;
}
