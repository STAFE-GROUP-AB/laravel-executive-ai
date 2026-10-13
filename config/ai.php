<?php

/*
|--------------------------------------------------------------------------
| laravel/ai 1.2.0 — the two shipped defaults we override (ADR-004 D1)
|--------------------------------------------------------------------------
|
| This is the SDK's published configuration with two deliberate changes,
| both of which exist because the shipped values would quietly pick a brand
| for the owner (§4 brand neutrality):
|
| 1. Every `default*` key below is `'unconfigured'`, a provider name that is
|    never defined. The package ships `'default' => 'openai'`, so a code path
|    that forgot to pass a provider would silently call OpenAI with whatever
|    `OPENAI_API_KEY` happened to be in the environment. With an undefined
|    name the same mistake throws, which is what we want to find in CI.
| 2. No provider entry carries a `models` key, so nothing can fall back to a
|    driver's hard-coded default model — `OllamaProvider::defaultTextModel()`
|    returns a hard-coded name when unconfigured. Our adapter always passes an
|    explicit model and `AiRequest` cannot be constructed without one
|    (contract matrix row 12).
|
| The provider entries themselves are unused at runtime: a provider is built
| on demand by `App\AI\Providers\LaravelAi\LaravelAiAdapterFactory` with its
| key read from the Keychain at call time, never from `env()` (ADR-003). They
| stay here so the SDK's own commands and a developer's `php artisan tinker`
| see the package's expected shape.
|
| The SDK's `agent_conversations` migration is never published or run:
| conversation and memory are app-owned (§4, ADR-004 D1), and a contract test
| proves prompting works with that table absent (matrix row 11).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'unconfigured',
    'default_for_images' => 'unconfigured',
    'default_for_audio' => 'unconfigured',
    'default_for_transcription' => 'unconfigured',
    'default_for_embeddings' => 'unconfigured',
    'default_for_reranking' => 'unconfigured',
    'default_for_classification' => 'unconfigured',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-6-sol'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-2.5-flare'),
            // ADR-004 / Europe First: never ask a vendor to retain responses unless the owner opts in.
            'store' => (bool) env('AZURE_OPENAI_STORE', false),
        ],

        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
            'assume_role' => [
                'arn' => env('AWS_BEDROCK_ASSUME_ROLE_ARN'),
                'session_name' => env('AWS_BEDROCK_ASSUME_ROLE_SESSION_NAME'),
                'duration_seconds' => env('AWS_BEDROCK_ASSUME_ROLE_DURATION_SECONDS'),
                'external_id' => env('AWS_BEDROCK_ASSUME_ROLE_EXTERNAL_ID'),
            ],
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            // ADR-004 / Europe First: never ask a vendor to retain responses unless the owner opts in.
            'store' => (bool) env('OPENAI_STORE', false),
        ],

        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
