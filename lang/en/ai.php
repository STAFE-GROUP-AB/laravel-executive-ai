<?php

declare(strict_types=1);

/*
 * AI-state copy for the PWA (S1-11; health contract §6, blueprint §0, §12.2, §12.3
 * screen 5). "AI ready" exists on exactly one key, state.ready, and
 * App\Http\View\AiStateCopy emits it on exactly one code path. Nothing here may
 * claim AI runs anywhere but the Mac (§1.2). `providers` keys equal the ids in
 * packaging/fixtures/ai-providers/ai-providers.json (SupportedProvidersTest).
 */
return [
    'state' => [
        'ready' => 'AI ready',
        'error' => 'AI error',
        'setup_required' => 'AI setup required',
    ],
    'badge_label' => 'AI state: :label',
    'select' => 'Select AI',
    'reconfigure' => 'Reconfigure AI',
    'description' => [
        'error' => 'The last AI run failed. Reconfigure AI on your Mac; your CRM, earlier briefs and the admin keep working meanwhile.',
        'not_configured' => 'No AI provider is selected yet. Your CRM, earlier briefs and the admin are available now. AI and the scheduler run on your Mac — choose a provider there to receive your first real brief.',
        'configured_untested' => 'A provider is selected, but no completion has succeeded yet, so AI is not ready. Finish the setup on your Mac; everything else keeps working.',
    ],
    'settings' => [
        'title' => 'Choose AI',
        'lead' => 'Pick what the assistant thinks with. Nothing is preselected, and nothing here is required to use the CRM or read briefs.',
        'current_heading' => 'Current choice',
        'current' => ':provider is selected. No completion has succeeded yet, so AI is not ready.',
        'current_ready' => ':provider is selected and has completed a request.',
        'none_selected' => 'No provider is selected. The app works without AI; new briefs start once a provider has completed one request.',
        'options_heading' => 'Options this build supports',
        'skip_title' => 'Continue without AI',
        'skip_detail' => 'Keep the CRM, earlier briefs and the admin. The setup banner stays until a provider has completed one request.',
        'kinds' => [
            'local' => 'Runs on your Mac. Nothing is sent to a provider.',
            'remote' => 'Your own account and API key. The key is stored in the macOS Keychain by the background service, never in a file.',
        ],
        'none_available' => 'No AI provider adapter is bound in this build yet, so no provider can be chosen. The list fills in as adapters land; everything else keeps working.',
        'where' => 'The choice is made on your Mac: open the menu bar panel and choose Settings. This page only shows what the core supports and never preselects anything.',
    ],
    'providers' => [
        'ollama' => 'Ollama',
        'lmstudio' => 'LM Studio',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'custom' => 'Other OpenAI-compatible endpoint',
    ],
];
